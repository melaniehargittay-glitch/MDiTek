<?php

/**
 * tabs_template.php
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Kevin Yeh <kevin.y@integralemr.com>
 * @copyright Copyright (c) 2016 Kevin Yeh <kevin.y@integralemr.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

?>

<script type="text/html" id="tabs-controls">
    <div class="tabControls" data-bind="with: tabs">
        <div class="tabsNoHover w-1">
            <svg id="patient_caret" data-dir="down" title="<?php echo xla('Toggle the Patient Panel'); ?>" style="width:14px;height:14px;cursor:pointer;display:block;color:#6c757d;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <!-- ko  foreach: tabsList -->
            <div class="tabSpan bgcolor2" data-bind="click: tabClicked, css: {tabsNoHover: !visible()}">
                <span class="tabTitle pr-2" data-bind="text: title, click: tabClicked, css: {tabHidden: !visible()}"></span>
                <span data-bind="click: tabRefresh, class: spinner" style="display:inline-flex;align-items:center;cursor:pointer;margin-right:3px;" title="Refresh"><svg style="width:11px;height:11px;color:#6c757d;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg></span>
                <!--ko if:!locked() -->
                    <span data-bind="click: tabLockToggle" style="display:inline-flex;align-items:center;cursor:pointer;margin-right:3px;" title="Lock tab"><svg style="width:11px;height:11px;color:#6c757d;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg></span>
                <!-- /ko -->
                <!--ko if:locked() -->
                    <span data-bind="click: tabLockToggle" style="display:inline-flex;align-items:center;cursor:pointer;margin-right:3px;" title="Unlock tab"><svg style="width:11px;height:11px;color:#6c757d;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
                <!-- /ko -->
                <!-- ko if:closable-->
                    <span data-bind="click: tabClose" style="display:inline-flex;align-items:center;cursor:pointer;" title="Close tab"><svg style="width:11px;height:11px;color:#6c757d;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span>
                <!-- /ko -->
            </div>
        <!-- /ko -->
        <div class="tabsNoHover w-100"></div>
    </div>
</script>
<script type="text/html" id="tabs-frames">

        <!-- ko  foreach: tabs.tabsList -->
        <div class="frameDisplay" data-bind="visible:visible">
            <h5 class="text-center position-relative" style="top: 50%;" data-bind="text: loading_text,visible:loading_text_status"></h5>
            <iframe data-bind="location: $data, iframeName: $data.name, ">

            </iframe>
        </div>
        <!-- /ko -->
</script>
