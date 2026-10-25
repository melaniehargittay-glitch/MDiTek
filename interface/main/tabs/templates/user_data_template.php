<?php

/**
 * user_data_template.php
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Kevin Yeh <kevin.y@integralemr.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Ranganath Pathak <pathak@scrs1.org>
 * @copyright Copyright (c) 2016 Kevin Yeh <kevin.y@integralemr.com>
 * @copyright Copyright (c) 2016 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019 Ranganath Pathak <pathak@scrs1.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

?>

<script type="text/html" id="user-data-template">
    <!-- ko with: user -->
    <div id="username" class="appMenu ml-3">
                <div class='menuLabel dropdown' id="username" title="<?php echo xla('Current user') ?>" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="fa-stack" style="width:2em;height:2em;line-height:2em;display:inline-flex;align-items:center;justify-content:center;position:relative;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6c757d" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="width:40px;height:40px;vertical-align:middle;">
                            <circle cx="12" cy="12" r="11"/>
                            <circle cx="12" cy="9" r="3.5"/>
                            <path d="M4.5 20.5c0-4 3.4-6.5 7.5-6.5s7.5 2.5 7.5 6.5"/>
                        </svg>
                    </span>
                    <ul id="userdropdown" class="userfunctions menuEntries dropdown-menu dropdown-menu-right menu-shadow-ovr rounded-0 border-0">
                        <li class="menuLabel">
                            <span class="font-weight-bold"><span data-bind="text:fname"></span> <span data-bind="text:lname"></span></span>
                        </li>
                        <li class="menuLabel" data-bind="click: editSettings"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;color:#6c757d;"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>&nbsp;<?php echo xlt("Settings");?></li>
                        <li class="menuLabel" data-bind="click: changePassword"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;color:#6c757d;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>&nbsp;<?php echo xlt("Change Password");?></li>
                        <li class="menuLabel" data-bind="click: changeMFA"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;color:#6c757d;"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 11-7.778 7.778 5.5 5.5 0 017.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>&nbsp;<?php echo xlt("MFA Management");?></li>
                        <div class="dropdown-divider"></div>
                        <li class="menuLabel" data-bind="click: function() {navigateTab('./../about_page.php', 'About', function() {activateTabByName('About',true);});}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;color:#6c757d;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>&nbsp;<?php echo xlt("About");?> <?php echo text($GLOBALS['openemr_name']); ?></li>
                        <div class="dropdown-divider"></div>
                        <li class="menuLabel" data-bind="click: logout"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;color:#6c757d;"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>&nbsp;<?php echo xlt("Logout");?></li>
                    </ul>
              </div>
        </div>
    <!-- /ko -->
</script>
