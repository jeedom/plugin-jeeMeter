<?php
/* Jeedom is free software: you can redistribute it and/or modify
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
* along with Jeedom. If not, see
<http: //www.gnu.org/licenses />.
*/

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function jeeMeter_install() {
	if (!file_exists(__DIR__ . '/jeeMeter_icon_alternate.png')) {
		return;
	}
	if (config::byKey('mbState') == 1) {
		$market = config::byKey('market::address');
		if (!empty($market) && $market != 'https://market.jeedom.com') {
			rename(__DIR__ . '/jeeMeter_icon.png', __DIR__ . '/jeeMeter_icon_default.png');
			rename(__DIR__ . '/jeeMeter_icon_alternate.png', __DIR__ . '/jeeMeter_icon.png');
		}
	}
}

function jeeMeter_update() {
	if (!file_exists(__DIR__ . '/jeeMeter_icon_alternate.png')) {
		return;
	}
	if (config::byKey('mbState') == 1) {
		$market = config::byKey('market::address');
		if (!empty($market) && $market != 'https://market.jeedom.com') {
			rename(__DIR__ . '/jeeMeter_icon.png', __DIR__ . '/jeeMeter_icon_default.png');
			rename(__DIR__ . '/jeeMeter_icon_alternate.png', __DIR__ . '/jeeMeter_icon.png');
		}
	}
}
