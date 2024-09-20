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

function printEqLogic(_eqLogic) {
  if (isset(_eqLogic.configuration.inputs)) {
    document.getElementById('selected_inputs').innerText = JSON.stringify(_eqLogic.configuration.inputs)
    document.getElementById('nb_inputs').innerText = _eqLogic.configuration.inputs.length
  }
}

function saveEqLogic(_eqLogic) {
  let inputs = document.getElementById('selected_inputs').innerText
  if (inputs != '') {
    _eqLogic.configuration.inputs = JSON.parse(inputs)
  } else {
    _eqLogic.configuration.inputs = []
  }
  return _eqLogic
}

document.getElementById('sel_inputs')?.addEventListener('click', function(_event) {
  _event.preventDefault()

  jeeDialog.dialog({
    id: 'md_selInputs',
    title: '{{Données à traiter}}',
    contentUrl: 'index.php?v=d&plugin=jeeMeter&modal=select.inputs&meterId=' + getUrlVars('id') + '&meterType=' + document.getElementById('sel_type').value,
    buttons: {
      confirm: {
        label: '<i class="fas fa-check"></i> {{Valider}}',
        callback: {
          click: function(_event) {
            var inputs = []
            document.getElementById('inputsTable').querySelectorAll('tbody>tr').forEach(_tr => {
              let input = _tr.getJeeValues('.inputAttr')[0]
              if (input.cmd != '') {
                let exists = inputs.findIndex((o) => o.cmd === input.cmd)
                if (exists === -1) {
                  inputs.push(input)
                }
              }
            })

            jeeFrontEnd.modifyWithoutSave = true
            document.getElementById('selected_inputs').innerText = JSON.stringify(inputs)
            document.getElementById('nb_inputs').innerText = inputs.length
            _event.target.closest('#md_selInputs')._jeeDialog.destroy()
          }
        }
      },
      cancel: {
        callback: {
          click: function(_event) {
            _event.target.closest('#md_selInputs')._jeeDialog.destroy()
          }
        }
      }
    }
  })
})

document.getElementById('sel_type')?.addEventListener('change', function() {
  let type = this.value
  if (type != '') {
    document.querySelectorAll('.sel_type:not(.' + type + ')').addClass('hidden')
    document.querySelectorAll('.sel_type.' + type).removeClass('hidden')
  }
})

document.querySelectorAll('.sel_tarif').forEach(_sel => {
  _sel.addEventListener('change', function() {
    if (this.value == 'double') {
      this.closest('.form-group').nextElementSibling.removeClass('hidden')
    } else {
      this.closest('.form-group').nextElementSibling.addClass('hidden')
    }
  })
})

document.querySelectorAll('.selectCmd').forEach(_selCmd => {
  initSelectCmd(_selCmd)
})

function initSelectCmd(_selCmd) {
  _selCmd.addEventListener('click', function(_event) {
    _event.stopImmediatePropagation()
    jeedom.cmd.getSelectModal({
      cmd: {
        type: this.dataset.type,
        subType: this.dataset.subtype
      }
    }, function(result) {
      let input = _selCmd.closest('.input-group').querySelector('input')
      input.value = result.human
      input.triggerEvent('change')
    })
  })
}

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }
  var tr = '<td class="hidden-xs">'
  tr += '<span class="cmdAttr" data-l1key="id"></span>'
  tr += '<input class="cmdAttr form-control input-sm" data-l1key="type" value="info" style="display:none;">'
  tr += '<input class="cmdAttr form-control input-sm" data-l1key="subType" value="numeric" style="display:none;">'
  tr += '</td>'
  tr += '<td>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom de la commande}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="cmdAttr" data-l1key="htmlstate"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label> '
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked>{{Historiser}}</label> '
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="unite" placeholder="Unité" title="{{Unité}}" style="width:30%;max-width:80px;margin-top:7px;">'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>'
  }
  tr += '<i class="fas fa-minus-circle pull-right cmdAction cursor" data-action="remove" title="{{Supprimer la commande}}"></i>'
  tr += '</td>'

  let newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList = 'cmd'
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}
