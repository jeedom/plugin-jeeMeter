<?php
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

require_once __DIR__  . '/../../../../core/php/core.inc.php';

class jeeMeter extends eqLogic {

  private static function createOCPPMeter(string $_tagId) {
    log::add(__CLASS__, 'debug', __('Création du compteur OCPP', __FILE__) . ' : ' . $_tagId);
    $meter = (new self)
      ->setEqType_name(__CLASS__)
      ->setName('OCPP ' . $_tagId)
      ->setConfiguration('type', 'ocpp')
      ->setConfiguration('tag_id', $_tagId);
    $meter->save();

    $listener = $meter->getListener();
    $listener->emptyEvent();
    $listener->addEvent('ocpp_transaction::' . $_tagId);
    $listener->save();
    return $meter;
  }

  public static function isPluginInstalled($_plugin): bool {
    try {
      plugin::byId($_plugin);
      return true;
    } catch (Exception $e) {
      return false;
    }
  }

  public static function postConfig_autoOCPP($_value) {
    $listenerOCPP = listener::byClassAndFunction(__CLASS__, 'autoOCPP');
    if (is_object($listenerOCPP)) {
      if ($_value == 0) {
        $listenerOCPP->remove();
      }
    } else if ($_value == 1) {
      $listenerOCPP = (new listener)
        ->setClass(__CLASS__)
        ->setFunction('autoOCPP');
      $listenerOCPP->addEvent('ocpp_transaction::*');
      $listenerOCPP->save();
    }
  }

  public static function autoOCPP($_options) {
    log::add(__CLASS__, 'debug', __FUNCTION__ . ' : ' . print_r($_options, true));
    $tagId = ocpp_transaction::byId($_options['object'])->getTagId();
    $meter = self::byTypeAndSearchConfiguration(__CLASS__, ['type' => 'ocpp', 'tag_id' => $tagId]);
    $meter = (isset($meter[0])) ? $meter[0] : '';
    if (!is_object($meter) && config::byKey('autoOCPP', __CLASS__, 0) == 1) {
      $meter = self::createOCPPMeter($tagId);
      $meter->getListener()->execute('ocpp_transaction::' . $tagId, $_options['value'], $_options['datetime'], $_options['object']);
    }
  }

  public static function createAllOCPPMeters() {
    $count = 0;
    $authGroups = (array) config::byKey('authGroups', 'ocpp', array());
    foreach (array_keys($authGroups) as $authGroupId) {
      $auths = ocpp::getAuthGroup($authGroupId);

      foreach (array_keys($auths) as $tagId) {
        $meter = self::byTypeAndSearchConfiguration(__CLASS__, ['type' => 'ocpp', 'tag_id' => $tagId]);
        $meter = (isset($meter[0])) ? $meter[0] : $meter;
        if (!is_object($meter) && $auths[$tagId]['status'] == 'Accepted') {
          $meter = self::createOCPPMeter($tagId);
          $meter->getIndexCmd();
          $meter->getPowerCmd();
          $count++;
        }
      }
    }
    return $count;
  }

  public static function updateIndex($_options) {
    log::add(__CLASS__, 'debug', __FUNCTION__ . ' : ' . print_r($_options, true));
    if (!is_object($meter = self::byId($_options['meter_id']))) {
      log::add(__CLASS__, 'error', __('Compteur introuvable (ID)', __FILE__) . ' : ' . $_options['meter_id']);
      listener::byId($_options['listener_id'])->remove();
      return false;
    }

    if ($_options['value'] == 'start_transaction') {
      $transaction = ocpp_transaction::byId($_options['object']);
      $ocppMeter = eqLogic::byLogicalId($transaction->getCpId(), 'ocpp');
      $connector = $transaction->getConnectorId();

      if (is_object($ocppIndex = $ocppMeter->getCmd('info', 'Energy.Active.Import.Register::' . $connector))) {
        $input[0] = array(
          'last_val' => $transaction->getOptions('meterStart'),
          'last_ts' => strtotime($transaction->getStart()),
          'unite' => 'Wh',
          'cmd' => '#' . $ocppIndex->getId() . '#'
        );

        $listener = listener::byId($_options['listener_id']);
        $listener->addEvent($input[0]['cmd']);
        $listener->save();

        $meter->setConfiguration('inputs', $input)->save(true);
      }

      if (is_object($ocppPower = $ocppMeter->getCmd('info', 'Power.Active.Import::' . $connector))) {
        $meter->updatePowerCmd($ocppPower->execCmd(), date('Y-m-d H:i:s'), $ocppPower->getUnite());
        $listener = $meter->getListener('power');
        $listener->addEvent($ocppPower->getId());
        $listener->save();
      }

      return;
    }

    if ($_options['value'] == 'stop_transaction') {
      $transaction = ocpp_transaction::byId($_options['object']);
      $listener = listener::byId($_options['listener_id']);
      if (count($listener->getEvent()) > 1) {
        $listener->emptyEvent();
        $listener->addEvent('ocpp_transaction::' . $meter->getConfiguration('tag_id'));
        $listener->save();
      }

      $listener = $meter->getListener('power');
      $listener->setEvent([]);
      $listener->save();
      $meter->updatePowerCmd(0, date('Y-m-d H:i:s'), 'W');

      $input = (array) $meter->getConfiguration('inputs', array());
      if (!isset($input[0]) || !is_array($input[0])) {
        $input[0] = array(
          'last_val' => $transaction->getOptions('meterStart'),
          'last_ts' => strtotime($transaction->getStart()),
          'unite' => 'Wh'
        );
      }
      $meter->updateIndexCmd($transaction->getOptions('meterStop'), strtotime($_options['datetime']), $input[0]);
      $meter->setConfiguration('inputs', array())->save(true);

      return;
    }

    $meterType = $meter->getConfiguration('type');
    $input = $meter->getInput($_options['event_id']);

    if ($meterType == 'custom') {
      if (!$input || !is_object($tagCmd = cmd::byId(trim($input['tag_id'], '#'))) || $tagCmd->execCmd() != $meter->getConfiguration('tag_id')) {
        return false;
      }
    } else if ($meterType == 'ocpp') {
      if (trim(cmd::byId($_options['event_id'])->getUnite()) == 'kWh') {
        $_options['value'] = $_options['value'] * 1000;
      }
    }

    $value = floatval($_options['value']);
    $timestamp = strtotime($_options['datetime']);
    $meter->updateIndexCmd($value, $timestamp, $input);
    $meter->updateInput(['cmd' => $input['cmd'], 'last_val' => $value, 'last_ts' => $timestamp]);
  }

  private function updateIndexCmd(float $_value, int $_timestamp, array $_input) {
    $duration = $_timestamp - $_input['last_ts'];
    switch ($_input['unite']) {
      case 'kWh':
        $index = round($_value - $_input['last_val'], 3);
        $indexCalcul = $_value . 'kWh - ' .  $_input['last_val'] . 'kWh = ' . $index . 'kWh';
        break;

      case 'Wh':
        $index = round(($_value - $_input['last_val']) / 1000, 3);
        $indexCalcul = '(' . $_value . 'Wh - ' .  $_input['last_val'] . 'Wh) / 1000 = ' . $index . 'kWh';
        break;

      case 'kW':
        $index = round(($_input['last_val'] * $duration) / 3600, 3);
        $indexCalcul = '(' . $_input['last_val'] . 'kW * ' .  $duration . 's) / 3600 = ' . $index . 'kWh';
        break;

      case 'W':
        $index = round((($_input['last_val'] * $duration) / 3600) / 1000, 3);
        $indexCalcul = '((' . $_input['last_val'] . 'W * ' .  $duration . 's) / 3600) / 1000 = ' . $index . 'kWh';
        break;

      default:
        log::add(__CLASS__, 'warning', $this->getHumanName() . ' ' . __("Impossible de calculer l'index (unité inconnue)", __FILE__) . ' : ' . print_r($_input, true));
        return false;
    }
    log::add(__CLASS__, 'debug', $this->getHumanName() . ' ' . __("Calcul de l'index", __FILE__) . ' : ' . $indexCalcul);

    $indexes['index'] = array(
      'value' => $index,
      'timestamp' => $_timestamp
    );
    if ($this->getConfiguration('tarif') == 'double') {
      if (is_object($switchCmd = cmd::byId(trim($this->getConfiguration('switch_tarif'), '#')))) {
        $switchVal = $switchCmd->execCmd();
        $switchTs = strtotime($switchCmd->getValueDate());
        if ($switchTs > $_input['last_ts'] && $switchTs <= $_timestamp) {
          $switchDuration = $switchTs - $_input['last_ts'];
          $indexes[($switchVal == 1) ? 'indexHC' : 'indexHP'] = array(
            'value' => round($index * ($switchDuration / $duration), 3),
            'timestamp' => $switchTs
          );
          $indexes[($switchVal == 1) ? 'indexHP' : 'indexHC'] = array(
            'value' => round($index * (($duration - $switchDuration) / $duration), 3),
            'timestamp' => $_timestamp
          );
        } else {
          $indexes[($switchVal == 1) ? 'indexHP' : 'indexHC'] = array(
            'value' => $index,
            'timestamp' => $_timestamp
          );
        }
      }
    }

    foreach ($indexes as $logical => $index) {
      if ($index['value'] > 0) {
        $cmd = $this->getIndexCmd($logical);
        $cmdValue = floatval($cmd->execCmd());
        $value = $cmdValue + $index['value'];
        $valueDate = date('Y-m-d H:i:s', $index['timestamp']);
        log::add(__CLASS__, 'debug', $cmd->getHumanName() . ' : ' . $cmdValue . ' + ' . $index['value'] . ' = ' . $value . ' (' . $valueDate . ')');
        $cmd->event($value, $valueDate);
      }
    }
  }

  public static function updatePower($_options) {
    log::add(__CLASS__, 'debug', __FUNCTION__ . ' : ' . print_r($_options, true));
    if (!is_object($meter = self::byId($_options['meter_id']))) {
      listener::byId($_options['listener_id'])->remove();
      log::add(__CLASS__, 'error', __('Compteur introuvable (ID)', __FILE__) . ' : ' . $_options['meter_id']);
      return false;
    }

    $meterType = $meter->getConfiguration('type');
    if ($meterType == 'custom') {
      $input = $meter->getInput($_options['event_id']);
      if (!$input || !is_object($tagCmd = cmd::byId(trim($input['tag_id'], '#'))) || $tagCmd->execCmd() != $meter->getConfiguration('tag_id')) {
        return false;
      }
      $unite = $input['unite'];
    } else if ($meterType == 'ocpp') {
      $unite = cmd::byId($_options['event_id'])->getUnite();
    } else {
      listener::byId($_options['listener_id'])->remove();
      return false;
    }

    $meter->updatePowerCmd($_options['value'], $_options['datetime'], $unite);
  }

  private function updatePowerCmd($_value, $_datetime, $_unite) {
    if (trim($_unite) == 'kW') {
      $_value = $_value * 1000;
    }

    $this->getPowerCmd()->event($_value, $_datetime);
  }

  public function preInsert() {
    $this->setIsEnable(1)
      ->setIsVisible(1)
      ->setCategory('energy', 1);

    if ($this->getConfiguration('type') == '') {
      $this->setConfiguration('type', 'default');
    }

    $tarif = config::byKey('default_tarif', __CLASS__);
    $this->setConfiguration('tarif', $tarif);
    if ($tarif == 'double') {
      $this->setConfiguration('switch_tarif', config::byKey('default_switch_tarif', __CLASS__));
    }
  }

  public function preUpdate() {
    if ($this->getIsEnable() == 1) {
      if ($this->getConfiguration('tarif') == 'double' && $this->getConfiguration('switch_tarif') == '') {
        throw new Exception(__('La commande de bascule de tarification doit être renseignée', __FILE__));
      }

      $meterType = $this->getConfiguration('type');
      $tagId = $this->getConfiguration('tag_id');

      if (in_array($meterType, ['custom', 'ocpp'])) {
        if (trim($tagId) == '') {
          throw new Exception(__("L'identifiant de l'utilisateur doit être renseigné", __FILE__));
        }
      }

      $this->getIndexCmd();

      $listener = $this->getListener();
      $listener->emptyEvent();
      $inputs = jeedom::fromHumanReadable($this->getConfiguration('inputs', array()));

      if ($meterType == 'ocpp') {
        $this->getPowerCmd();

        $listener->addEvent('ocpp_transaction::' . $tagId);
        if (isset($inputs[0]) /*&& is_object($cmd = cmd::byId(trim($inputs[0]['cmd'], '#'))) && $cmd->getEqType() == $meterType*/) {
          $inputs = $inputs[0];
          $listener->addEvent($inputs[0]['cmd']);
        } else {
          $inputs = array();
        }
      } else {
        if ($meterType == 'custom') {
          $powerListener = $this->getListener('power');
          $powerListener->emptyEvent();
        }

        foreach ($inputs as $i => $input) {
          if (is_object($cmd = cmd::byId(trim($input['cmd'], '#')))) {
            $listener->addEvent($cmd->getId());
            if (!isset($input['last_val'])) {
              $inputs[$i]['last_val'] = floatval($cmd->execCmd());
              $inputs[$i]['last_ts'] = strtotime($cmd->getValueDate());
            }

            if (isset($powerListener) && in_array($input['unite'], ['W', 'kW'])) {
              $powerListener->addEvent($cmd->getId());
            }
          }
        }
      }
      $this->setConfiguration('inputs', $inputs);
      $listener->save();

      if (isset($powerListener)) {
        if (!empty($powerListener->getEvent())) {
          $this->getPowerCmd();
        }
        $powerListener->save();
      }
    } else {
      $this->removeListeners();
    }
  }

  public function preRemove() {
    $this->removeListeners();
  }

  private function getListener(string $_type = 'index'): object {
    $function = ($_type == 'power') ? 'updatePower' : 'updateIndex';
    $listener = listener::byClassAndFunction(__CLASS__, $function, ['meter_id' => (string) $this->getId()]);
    if (!is_object($listener)) {
      $listener = (new listener)
        ->setClass(__CLASS__)
        ->setFunction($function)
        ->setOption('meter_id', $this->getId());
    }
    return $listener;
  }

  public function removeListeners() {
    $functions = array('updateIndex');
    if (in_array($this->getConfiguration('type'), ['custom', 'ocpp'])) {
      array_push($functions, 'updatePower');
    }

    log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . $this->getId());
    foreach ($functions as $function) {
      $listener = listener::byClassAndFunction(__CLASS__, $function, ['meter_id' => (string) $this->getId()]);
      log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . print_r($listener, true));
      if (is_object($listener)) {
        $listener->remove();
      }
    }
  }

  private function getIndexCmd(string $_logicalId = null) {
    $cmds = array(
      'simple' => ['index' => __('Index', __FILE__)],
      'double' => ['indexHP' => __('Index heures pleines', __FILE__), 'indexHC' => __('Index heures creuses', __FILE__), 'index' => __('Index total', __FILE__)]
    );
    foreach ($cmds[$this->getConfiguration('tarif')] as $cmdLogical => $cmdName) {
      $cmd = $this->getCmd('info', $cmdLogical);
      if (!is_object($cmd)) {
        $cmd = (new jeeMeterCmd)
          ->setLogicalId($cmdLogical)
          ->setEqLogic_id($this->getId())
          ->setName($cmdName)
          ->setType('info')
          ->setSubType('numeric')
          ->setUnite('kWh')
          ->setGeneric_type('CONSUMPTION')
          ->setTemplate('dashboard', 'tile')
          ->setTemplate('mobile', 'tile')
          ->setDisplay('showStatsOndashboard', 0)
          ->setDisplay('showStatsOnmobile', 0)
          ->setIsVisible(1)
          ->setIsHistorized(1);
        $cmd->save();
      }

      if ($cmdLogical == $_logicalId) {
        return $cmd;
      }
    }
  }

  private function getPowerCmd() {
    $cmd = $this->getCmd('info', 'power');
    if (!is_object($cmd)) {
      $cmd = (new jeeMeterCmd)
        ->setLogicalId('power')
        ->setEqLogic_id($this->getId())
        ->setName(__('Puissance', __FILE__))
        ->setType('info')
        ->setSubType('numeric')
        ->setUnite('W')
        ->setGeneric_type('POWER')
        ->setTemplate('dashboard', 'tile')
        ->setTemplate('mobile', 'tile')
        ->setDisplay('showStatsOndashboard', 0)
        ->setDisplay('showStatsOnmobile', 0)
        ->setIsVisible(1)
        ->setIsHistorized(1);
      $cmd->save();
    }
    return $cmd;
  }

  private function getInput($_cmdId) {
    foreach ($this->getConfiguration('inputs') as $input) {
      if ($input['cmd'] == '#' . $_cmdId . '#') {
        return $input;
      }
    }
    return false;
  }

  private function updateInput(array $_input) {
    $inputs = $this->getConfiguration('inputs');
    foreach ($inputs as $i => $input) {
      if ($input['cmd'] == $_input['cmd']) {
        $inputs[$i] = array_merge($input, $_input);
        break;
      }
    }
    $this->setConfiguration('inputs', $inputs)->save(true);
  }
}

class jeeMeterCmd extends cmd {
  public function execute($_options = array()) {
  }
}
