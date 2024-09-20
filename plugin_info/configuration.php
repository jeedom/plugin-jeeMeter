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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
  include_file('desktop', '404', 'php');
  die();
}
?>
<form class="form-horizontal">
  <fieldset>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Tarification par défaut}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Renseignez la tarification par défaut à la création d'un compteur virtuel}}"></i></sup>
      </label>
      <div class="col-md-4">
        <select class="configKey form-control sel_tarif" data-l1key="default_tarif">
          <option value="simple">{{Tarif unique}}</option>
          <option value="double">{{Heures pleines/Heures creuses}}</option>
        </select>
      </div>
    </div>
    <div class="form-group hidden">
      <label class="col-md-4 control-label">{{Bascule de tarification}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Commande info/binaire définissant la tarification en cours (1=heures pleines/0=heures creuses)}}"></i></sup>
      </label>
      <div class="input-group col-md-4">
        <input type="text" class="configKey form-control roundedLeft" data-l1key="default_switch_tarif" placeholder="{{Commande info/binaire de tarification}}">
        <span class="input-group-btn">
          <a class="btn btn-default selectCmd roundedRight" data-type="info" data-subType="binary"><i class="fas fa-list-alt"></i></a>
        </span>
      </div>
    </div>
    <?php
    if (jeeMeter::isPluginInstalled('ocpp')) {
    ?>
      <div class="form-group">
        <label class="col-md-4 control-label">{{OCPP}}
          <sup><i class="fas fa-question-circle tooltips" title="{{Plugin OCPP détecté}}"></i></sup>
        </label>
        <div class="col-md-4">
          <label class="checkbox-inline"><input type="checkbox" class="configKey" data-l1key="autoOCPP">{{Création automatique des compteurs}}
            <sup><i class="fas fa-question-circle tooltips" title="{{Permet de comptabiliser les consommations de chaque utilisateur}}"></i></sup>
          </label>
        </div>
      </div>
    <?php
    }
    ?>
  </fieldset>
</form>

<?php include_file('desktop', 'jeeMeter', 'js', 'jeeMeter'); ?>
