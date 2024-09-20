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
if (!isConnect()) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$meterId = init('meterId');
if (!is_object($meter = jeeMeter::byId($meterId))) {
	throw new Exception('{{Compteur introuvable}} : ' . $meterId);
	die();
}
$meterType = init('meterType', $meter->getConfiguration('type'));
sendVarToJS([
	'_meterId' => $meterId,
	'_meterType' => $meterType,
]);
?>

<button class="btn btn-primary pull-right" id="addInput"><i class="fas fa-plus-circle"></i> {{Ajouter une entrée}}</button>

<table class="table table-bordered table-condensed tablesorter stickyHead" id="inputsTable">
	<thead>
		<tr>
			<th>{{Données}}
				<sup><i class="fas fa-question-circle" title="{{Commande donnant les données brutes}}"></i></sup>
			</th>
			<th>{{Unité}}
				<sup><i class="fas fa-question-circle" title="{{Unité des données brutes}}"></i></sup>
			</th>
			<?php
			if ($meterType == 'custom') {
			?>
				<th>{{Identifiant}}
					<sup><i class="fas fa-question-circle" title="{{Commande donnant l'identifiant de l'utilisateur'}}"></i></sup>
				</th>
				<!-- <th>{{Etat}}
					<sup><i class="fas fa-question-circle" title="{{Commande donnant l'état de l'équipement}}"></i></sup>
				</th>
				<th>{{Arrêt}}
					<sup><i class="fas fa-question-circle" title="{{Commande d'arrêt de l'équipement}}"></i></sup>
				</th> -->
			<?php
			}
			?>
			<th></th>
		</tr>
	</thead>
	<tbody>
	</tbody>
</table>

<script>
	var _inputs = document.getElementById('selected_inputs').innerText
	if (_inputs.length <= 2) {
		addInput()
	} else {
		JSON.parse(_inputs).forEach(_input => {
			addInput(_input)
		})
	}

	document.getElementById('md_selInputs').addEventListener('click', function(_event) {
		var _target = null

		if (_target = _event.target.closest('#addInput')) {
			_event.stopImmediatePropagation()
			addInput()
			return
		}

		if (_target = _event.target.closest('.removeInput')) {
			_target.closest('tr').remove()
			return
		}
	})

	function addInput(_input) {
		var tr = '<tr>'
		tr += '<td>'
		tr += '<div class="input-group">'
		tr += '<input type="text" class="inputAttr form-control roundedLeft" data-l1key="cmd" placeholder="{{Commande info/numérique}}">'
		tr += '<span class="input-group-btn">'
		tr += '<a class="btn btn-default selectCmd roundedRight" data-type="info" data-subtype="numeric"><i class="fas fa-list-alt"></i></a>'
		tr += '</span>'
		tr += '</div>'
		tr += '</td>'
		tr += '<td>'
		tr += '<select class="inputAttr form-control" data-l1key="unite">'
		tr += '<option value="">--- {{A renseigner}} ---</option>'
		tr += '<option value="Wh">{{Index en Watts-heure}} (Wh)</option>'
		tr += '<option value="kWh">{{Index en kiloWatts-heure}} (kWh)</option>'
		tr += '<option value="W">{{Puissance en Watts}} (W)</option>'
		tr += '<option value="kW">{{Puissance en kiloWatts}} (kW)</option>'
		tr += '</select>'
		tr += '</td>'
		if (_meterType == 'custom') {
			tr += '<td>'
			tr += '<div class="input-group">'
			tr += '<input type="text" class="inputAttr form-control roundedLeft" data-l1key="tag_id" placeholder="{{Commande info/autre}}">'
			tr += '<span class="input-group-btn">'
			tr += '<a class="btn btn-default selectCmd roundedRight" data-type="info" data-subtype="string"><i class="fas fa-list-alt"></i></a>'
			tr += '</span>'
			tr += '</div>'
			tr += '</td>'
			// tr += '<td>'
			// tr += '<div class="input-group">'
			// tr += '<input type="text" class="inputAttr form-control roundedLeft" data-l1key="state" placeholder="{{Commande info/binaire}}">'
			// tr += '<span class="input-group-btn">'
			// tr += '<a class="btn btn-default selectCmd roundedRight" data-type="info" data-subtype="binary"><i class="fas fa-list-alt"></i></a>'
			// tr += '</span>'
			// tr += '</div>'
			// tr += '</td>'
			// tr += '<td>'
			// tr += '<div class="input-group">'
			// tr += '<input type="text" class="inputAttr form-control roundedLeft" data-l1key="stop" placeholder="{{Commande action/défaut}}">'
			// tr += '<span class="input-group-btn">'
			// tr += '<a class="btn btn-default selectCmd roundedRight" data-type="action" data-subtype="other"><i class="fas fa-list-alt"></i></a>'
			// tr += '</span>'
			// tr += '</div>'
			// tr += '</td>'
		}
		tr += '<td>'
		tr += '<button class="btn btn-sm btn-danger pull-right removeInput" title="Supprimer l\'entrée"><i class="fas fa-minus-circle"></i></button>'
		tr += '</td>'
		tr += '</tr>'

		let newRow = document.createElement('tr')
		newRow.innerHTML = tr
		newRow.setJeeValues(_input, '.inputAttr')
		document.getElementById('inputsTable').querySelector('tbody').appendChild(newRow)
		newRow.querySelectorAll('.selectCmd').forEach(_selCmd => {
			initSelectCmd(_selCmd)
		})

		newRow.querySelector('.inputAttr[data-l1key="cmd"]').addEventListener('change', function() {
			let humanName = this.value.trim()
			if (humanName != '') {
				jeedom.cmd.byHumanName({
					humanName: humanName,
					success: function(_cmd) {
						let cmdUnite = _cmd.unite.trim()
						if (['Wh', 'kWh', 'W', 'kW'].includes(cmdUnite)) {
							newRow.querySelector('.inputAttr[data-l1key="unite"]').value = cmdUnite
						}
					}
				})
			}
		})
	}
</script>

<?php include_file('desktop', 'jeeMeter', 'js', 'jeeMeter'); ?>
