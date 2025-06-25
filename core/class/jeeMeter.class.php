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

  public static function createAllOCPPMeters(): int {
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
    $meter = self::byId($_options['meter_id']);
    if (!is_object($meter)) {
      log::add(__CLASS__, 'error', '[' . __FUNCTION__ . '] ' . __('Compteur introuvable (ID)', __FILE__) . ' : ' . $_options['meter_id']);
      listener::byId($_options['listener_id'])->remove();
      return false;
    }
    log::add(__CLASS__, 'debug', $meter->getHumanName() . '[' . __FUNCTION__ . '] : ' . print_r($_options, true));

    $meterType = $meter->getConfiguration('type');
    if ($meterType == 'ocpp') {
      if (is_numeric($_options['value'])) {
        $cmd = cmd::byId($_options['event_id']);
        if (is_object($cmd) && trim($cmd->getUnite()) == 'kWh') {
          $_options['value'] = $_options['value'] * 1000;
        }
      } else {
        $listener = listener::byId($_options['listener_id']);
        $transaction = ocpp_transaction::byId($_options['object']);
        $ocppMeter = eqLogic::byLogicalId($transaction->getCpId(), 'ocpp');
        $connector = $transaction->getConnectorId();
        $inputs = (array) $meter->getConfiguration('inputs', array());

        if ($_options['value'] == 'start_transaction') {
          if (is_object($ocppIndex = $ocppMeter->getCmd('info', 'Energy.Active.Import.Register::' . $connector))) {
            $inputs[$transaction->getId()] = array(
              'last_val' => $transaction->getOptions('meterStart'),
              'last_ts' => strtotime($transaction->getStart()),
              'unite' => 'Wh',
              'cmd' => '#' . $ocppIndex->getId() . '#'
            );
            $meter->setConfiguration('inputs', $inputs)->save(true);

            $listener->addEvent($ocppIndex->getId());
            $listener->save();

            if (is_object($ocppPower = $ocppMeter->getCmd('info', 'Power.Active.Import::' . $connector))) {
              $powerListener = $meter->getListener('power');
              $powerListener->addEvent($ocppPower->getId());
              $powerListener->save();
              $meter->updatePowerCmd($ocppPower->getId(), $ocppPower->execCmd(), $ocppPower->getCollectDate());
            }
          }

          return;
        } else if ($_options['value'] == 'stop_transaction') {
          if (!isset($inputs[$transaction->getId()]) || !is_array($inputs[$transaction->getId()])) {
            $inputs[$transaction->getId()] = array(
              'last_val' => $transaction->getOptions('meterStart'),
              'last_ts' => strtotime($transaction->getStart()),
              'unite' => 'Wh'
            );
          } else {
            $meter->removeListenerEvent($inputs[$transaction->getId()]['cmd'])->save();
            if (is_object($ocppPower = $ocppMeter->getCmd('info', 'Power.Active.Import::' . $connector))) {
              $meter->updatePowerCmd($ocppPower->getId(), 0, date('Y-m-d H:i:s'));
              $meter->removeListenerEvent($ocppPower->getId(), 'power')->save();
            }
          }

          $meter->updateIndexCmd($transaction->getOptions('meterStop'), strtotime($_options['datetime']), $inputs[$transaction->getId()]);
          unset($inputs[$transaction->getId()]);
          $meter->setConfiguration('inputs', $inputs)->save(true);

          return;
        }
      }
    }

    $input = $meter->getInput($_options['event_id']);
    if (!$input) {
      return false;
    }

    $value = floatval($_options['value']);
    $timestamp = strtotime($_options['datetime']);
    $meter->updateInput(['cmd' => $input['cmd'], 'last_val' => $value, 'last_ts' => $timestamp]);

    if ($meterType == 'custom') {
      if (!is_object($tagCmd = cmd::byId(trim($input['tag_id'], '#'))) || $tagCmd->execCmd() != $meter->getConfiguration('tag_id')) {
        return false;
      }
    }
    $meter->updateIndexCmd($value, $timestamp, $input);
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
    $meter = self::byId($_options['meter_id']);
    if (!is_object($meter)) {
      log::add(__CLASS__, 'error', '[' . __FUNCTION__ . '] ' . __('Compteur introuvable (ID)', __FILE__) . ' : ' . $_options['meter_id']);
      listener::byId($_options['listener_id'])->remove();
      return false;
    }
    log::add(__CLASS__, 'debug', $meter->getHumanName() . '[' . __FUNCTION__ . '] : ' . print_r($_options, true));
    $meter->updatePowerCmd($_options['event_id'], $_options['value'], $_options['datetime']);
  }

  private function updatePowerCmd($_powerId, $_value, $_datetime) {
    $meterType = $this->getConfiguration('type');
    $powerListener = $this->getListener('power');

    if (!in_array($meterType, ['custom', 'ocpp'])) {
      $powerListener->remove();
      return false;
    }

    $power = 0;

    foreach ($powerListener->getEvent() as $powerEvent) {
      $powerEvent = trim($powerEvent, '#');
      if ($meterType == 'custom') {
        $input = $this->getInput($powerEvent);
        if (!$input || !is_object($tagCmd = cmd::byId(trim($input['tag_id'], '#'))) || $tagCmd->execCmd() != $this->getConfiguration('tag_id')) {
          continue;
        }
        $value = ($powerEvent == $_powerId) ? $_value : cmd::byId($powerEvent)->execCmd();
        $unite = $input['unite'];
      } else if ($meterType == 'ocpp') {
        $powerCmd = cmd::byId($powerEvent);
        $value = ($powerEvent == $_powerId) ? $_value : $powerCmd->execCmd();
        $unite = $powerCmd->getUnite();
      }

      $power += (trim($unite) == 'kW') ? $value * 1000 : $value;
    }

    $this->getPowerCmd()->event($power, $_datetime);
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
        foreach ($inputs as $i => $input) {
          $transaction = ocpp_transaction::byId($i);
          if (is_object($transaction) && $transaction->getTagId() == $tagId) {
            $listener->addEvent($input['cmd']);
          } else {
            unset($inputs[$i]);
          }
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

    foreach ($functions as $function) {
      $listener = listener::byClassAndFunction(__CLASS__, $function, ['meter_id' => (string) $this->getId()]);
      if (is_object($listener)) {
        $listener->remove();
      }
    }
  }

  private function removeListenerEvent($_id, string $_type = 'index'): object {
    $listener = $this->getListener($_type);
    $events = $listener->getEvent();
    if (!is_array($events)) {
      $events = array();
    }
    $id = trim($_id, '#');
    $listener->setEvent(array_values(array_diff($events, ['#' . $id . '#'])));
    return $listener;
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
