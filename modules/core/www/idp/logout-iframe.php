<?php

use Exception;
use SimpleSAML\Auth;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\IdP;
use SimpleSAML\Metadata\MetaDataStorageHandler;
use SimpleSAML\Stats;
use SimpleSAML\XHTML\Template;

if (!isset($_REQUEST['id'])) {
    throw new Error\BadRequest('Missing required parameter: id');
}

if (isset($_REQUEST['type'])) {
    $type = (string) $_REQUEST['type'];
    if (!in_array($type, ['init', 'js', 'nojs', 'embed'], true)) {
        throw new Error\BadRequest('Invalid value for type.');
    }
} else {
    $type = 'init';
}

$globalConfig = Configuration::getInstance();
$logger = $globalConfig::getLogger();
if ($type !== 'embed') {
    $logger->stats('slo-iframe ' . $type);
    Stats::log('core:idp:logout-iframe:page', ['type' => $type]);
}

/** @psalm-var array $state */
$state = Auth\State::loadState($_REQUEST['id'], 'core:Logout-IFrame');
$idp = IdP::getByState($state);
$mdh = MetaDataStorageHandler::getMetadataHandler();

if ($type !== 'init') {
    // update association state
    foreach ($state['core:Logout-IFrame:Associations'] as $assocId => &$sp) {
        $spId = sha1($assocId);

        // move SPs from 'onhold' to 'inprogress'
        if ($sp['core:Logout-IFrame:State'] === 'onhold') {
            $sp['core:Logout-IFrame:State'] = 'inprogress';
        }

        // check for update through request
        if (isset($_REQUEST[$spId])) {
            $s = $_REQUEST[$spId];
            if ($s == 'completed' || $s == 'failed') {
                $sp['core:Logout-IFrame:State'] = $s;
            }
        }

        // check for timeout
        if (isset($sp['core:Logout-IFrame:Timeout']) && $sp['core:Logout-IFrame:Timeout'] < time()) {
            if ($sp['core:Logout-IFrame:State'] === 'inprogress') {
                $sp['core:Logout-IFrame:State'] = 'failed';
            }
        }

        // update the IdP
        if ($sp['core:Logout-IFrame:State'] === 'completed') {
            $idp->terminateAssociation($assocId);
        }

        if (!isset($sp['core:Logout-IFrame:Timeout'])) {
            if (method_exists($sp['Handler'], 'getAssociationConfig')) {
                $assocIdP = IdP::getByState($sp);
                $assocConfig = call_user_func([$sp['Handler'], 'getAssociationConfig'], $assocIdP, $sp);
                $sp['core:Logout-IFrame:Timeout'] = $assocConfig->getInteger('core:logout-timeout', 5) + time();
            } else {
                $sp['core:Logout-IFrame:Timeout'] = time() + 5;
            }
        }
    }
}

$associations = $idp->getAssociations();
foreach ($state['core:Logout-IFrame:Associations'] as $assocId => &$sp) {
    // in case we are refreshing a page
    if (!isset($associations[$assocId])) {
        $sp['core:Logout-IFrame:State'] = 'completed';
    }

    try {
        $assocIdP = IdP::getByState($sp);
        $url = call_user_func([$sp['Handler'], 'getLogoutURL'], $assocIdP, $sp, null);
        $sp['core:Logout-IFrame:URL'] = $url;
    } catch (Exception $e) {
        $sp['core:Logout-IFrame:State'] = 'failed';
    }
}

// get the metadata of the service that initiated logout, if any
$terminated = null;
if ($state['core:TerminatedAssocId'] !== null) {
    $mdset = 'saml20-sp-remote';
    if (substr($state['core:TerminatedAssocId'], 0, 4) === 'adfs') {
        $mdset = 'adfs-sp-remote';
    }
    $terminated = $mdh->getMetaDataConfig($state['saml:SPEntityId'], $mdset)->toArray();
}

// build an array with information about all services currently logged in
$remaining = [];
foreach ($state['core:Logout-IFrame:Associations'] as $association) {
    $key = sha1($association['id']);
    $mdset = 'saml20-sp-remote';
    if (substr($association['id'], 0, 4) === 'adfs') {
        $mdset = 'adfs-sp-remote';
    }

    if ($association['core:Logout-IFrame:State'] === 'completed') {
        continue;
    }

    $remaining[$key] = [
        'id' => $association['id'],
        'expires_on' => $association['Expires'],
        'entityID' => $association['saml:entityID'],
        'subject' => $association['saml:NameID'],
        'status' => $association['core:Logout-IFrame:State'],
        'metadata' => $mdh->getMetaDataConfig($association['saml:entityID'], $mdset)->toArray(),
    ];
    if (isset($association['core:Logout-IFrame:URL'])) {
        $remaining[$key]['logoutURL'] = $association['core:Logout-IFrame:URL'];
    }
    if (isset($association['core:Logout-IFrame:Timeout'])) {
        $remaining[$key]['timeout'] = $association['core:Logout-IFrame:Timeout'];
    }
}

if ($type === 'nojs') {
    $t = new Template($globalConfig, 'core:logout-iframe-wrapper.twig');
} else {
    $t = new Template($globalConfig, 'core:logout-iframe.twig');
}

$id = Auth\State::saveState($state, 'core:Logout-IFrame');
$t->data['auth_state'] = $id;
$t->data['type'] = $type;
$t->data['terminated_service'] = $terminated;
$t->data['remaining_services'] = $remaining;
$t->send();
