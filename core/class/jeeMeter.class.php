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
    $tagId = $_options['object']->getTagId();
    $meter = self::byTypeAndSearchConfiguration(__CLASS__, ['type' => 'ocpp', 'tag_id' => $tagId]);
    if (!is_object($meter) && config::byKey('autoOCPP', __CLASS__, 0) == 1) {
      $meter = (new meter)
        ->setName('OCPP ' . $tagId)
        ->setConfiguration('type', 'ocpp')
        ->setConfiguration('tag_id', $tagId);
      $meter->save();

      $listener = $meter->getListener();
      $listener->addEvent('ocpp_transaction::' . $tagId)->save();
      $listener->execute('ocpp_transaction::' . $tagId, $_options['value'], $_options['datetime'], $_options['object']);
    }
  }

  public static function updateIndex($_options) {
    log::add(__CLASS__, 'debug', __FUNCTION__ . ' : ' . print_r($_options, true));
    if (!is_object($meter = self::byId($_options['meter_id']))) {
      log::add(__CLASS__, 'error', __('Compteur introuvable (ID)', __FILE__) . ' : ' . $_options['meter_id']);
      listener::byId($_options['listener_id'])->remove();
      return false;
    }

    $meterType = $meter->getConfiguration('type');
    $input = $meter->getInput($_options['event_id']);

    if ($meterType == 'custom') {
      if (!$input || !is_object($tagCmd = cmd::byId(trim($input['tag_id'], '#'))) || $tagCmd->execCmd() != $meter->getConfiguration('tag_id')) {
        return false;
      }
    } else if ($meterType == 'ocpp') {
      if (!$input) {
        $input = array(
          'last_val' => $_options['object']->getOptions('meterStart'),
          'last_ts' => strtotime($_options['object']->getStart()),
          'unite' => 'Wh'
        );
        $ocppMeter = eqLogic::byId($_options['object']->getEqLogicId());
        if (is_object($ocppMeter) && is_object($ocppCmd = $ocppMeter->getCmd('info', 'Energy.Active.Import.Register::' . $_options['object']->getConnectorId()))) {
          $input['cmd'] = '#' . $ocppCmd->getId() . '#';
        }
      }

      if ($_options['value'] == 'start_transaction') {
        if (isset($input['cmd'])) {
          listener::byId($_options['listener_id'])->addEvent($input['cmd'])->save();
        }
        return $meter->setConfiguration('inputs', $input)->save(true);
      }
      if ($_options['value'] == 'stop_transaction') {
        listener::byId($_options['listener_id'])->emptyEvent()->addEvent('ocpp_transaction::' . $meter->getConfiguration('tag_id'))->save();
        $meter->updateIndexCmd($_options['object']->getOptions('meterStop'), strtotime($_options['datetime']), $input);
        return $meter->setConfiguration('inputs', array())->save(true);
      }
      if (cmd::byId($_options['event_id'])->getUnite() == 'kWh') {
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

    $indexes = array();
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
    } else {
      $indexes['index'] = array(
        'value' => $index,
        'timestamp' => $_timestamp
      );
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
    $listener = $this->getListener();
    if ($this->getIsEnable() == 1) {
      $meterType = $this->getConfiguration('type');
      $tagId = $this->getConfiguration('tag_id');

      if ($this->getConfiguration('tarif') == 'double' && $this->getConfiguration('switch_tarif') == '') {
        throw new Exception(__('La commande de bascule de tarification doit être renseignée', __FILE__));
      }
      if (in_array($meterType, ['custom', 'ocpp']) && $tagId == '') {
        throw new Exception(__("L'identifiant de l'utilisateur doit être renseigné", __FILE__));
      }

      $this->getIndexCmd();

      $inputs = jeedom::fromHumanReadable($this->getConfiguration('inputs'));
      if ($meterType == 'ocpp') {
        $listener->addEvent('ocpp_transaction::' . $tagId);
        if (isset($inputs[0]) && is_object($cmd = cmd::byId(trim($inputs[0]['cmd'], '#'))) && $cmd->getEqType() == $meterType) {
          $inputs = $inputs[0];
          $listener->addEvent($inputs[0]['cmd']);
        } else {
          $inputs = array();
        }
      } else {
        foreach ($inputs as $i => $input) {
          if (is_object($cmd = cmd::byId(trim($input['cmd'], '#')))) {
            $listener->addEvent($cmd->getId());
            if (!isset($input['last_val'])) {
              $inputs[$i]['last_val'] = floatval($cmd->execCmd());
              $inputs[$i]['last_ts'] = strtotime($cmd->getValueDate());
            }
          }
        }
      }
      $this->setConfiguration('inputs', $inputs);
      $listener->save();
    } else if ($listener->getId() != '') {
      $listener->remove();
    }
  }

  public function preRemove() {
    $listener = $this->getListener();
    if ($listener->getId() != '') {
      $listener->remove();
    }
  }

  private function getListener(): object {
    $listener = listener::byClassAndFunction(__CLASS__, 'updateIndex', ['meter_id' => $this->getId()]);
    if (is_object($listener)) {
      $listener->emptyEvent();
    } else {
      $listener = (new listener)
        ->setClass(__CLASS__)
        ->setFunction('updateIndex')
        ->setOption('meter_id', $this->getId());
    }
    return $listener;
  }

  private function getIndexCmd(string $_logicalId = null) {
    $cmds = array(
      'simple' => ['index' => __('Index', __FILE__)],
      'double' => ['indexHP' => __('Index heures pleines', __FILE__), 'indexHC' => __('Index heures creuses', __FILE__)]
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
          // ->setConfiguration('historizeRound', 3)
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

  private function getInput(int $_cmdId) {
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
