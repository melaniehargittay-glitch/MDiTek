<?php
/**
 * CAMOS view.php — restyled
 */

require_once(__DIR__ . "/../../globals.php");
require_once("../../../library/api.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$out_of_encounter = false;
if ((($_SESSION['encounter'] == '') || ($_SESSION['pid'] == '')) || ($_GET['mode'] == 'external')) {
    $out_of_encounter = true;
}

function myauth() { return 1; }

$break = "/* ---------------------------------- */";
$delete_subdata = true;
$limit = 100;
$select_size = 20;
$textarea_rows = 20;
$textarea_cols = 80;
$debug = '';
$error = '';

$preselect_category = '';
$preselect_subcategory = '';
$preselect_item = '';
$preselect_category_override = '';
$preselect_subcategory_override = '';
$preselect_item_override = '';

$quote_search = array("\r","\n");
$quote_replace = array("\\r","\\n");
$quote_search_content = array("\r","\n");
$quote_replace_content = array("\\r","\\n");
$category   = str_replace($quote_search, $quote_replace, $_POST['change_category'] ?? '');
$subcategory= str_replace($quote_search, $quote_replace, $_POST['change_subcategory'] ?? '');
$item       = str_replace($quote_search, $quote_replace, $_POST['change_item'] ?? '');
$content    = str_replace($quote_search_content, $quote_replace_content, $_POST['textarea_content'] ?? '');

if ($_POST['hidden_category'])    $preselect_category    = $_POST['hidden_category'];
if ($_POST['hidden_subcategory']) $preselect_subcategory = $_POST['hidden_subcategory'];
if ($_POST['hidden_item'])        $preselect_item        = $_POST['hidden_item'];

// Handle database changes
if (substr($_POST['hidden_mode'] ?? '', 0, 3) == 'add') {
    if ($_POST['hidden_selection'] == 'change_category') {
        $preselect_category_override = $_POST['change_category'];
        sqlStatement("INSERT INTO " . mitigateSqlTableUpperCase("form_CAMOS_category") . " (user, category) values (?, ?)", array($_SESSION['authUser'], $category));
    } elseif ($_POST['hidden_selection'] == 'change_subcategory') {
        $preselect_subcategory_override = $_POST['change_subcategory'];
        $category_id = $_POST['hidden_category'];
        if ($category_id >= 0)
            sqlStatement("INSERT INTO " . mitigateSqlTableUpperCase("form_CAMOS_subcategory") . " (user, subcategory, category_id) values (?, ?, ?)", array($_SESSION['authUser'], $subcategory, $category_id));
    } elseif ($_POST['hidden_selection'] == 'change_item') {
        $preselect_item_override = $_POST['change_item'];
        $category_id = $_POST['hidden_category'];
        $subcategory_id = $_POST['hidden_subcategory'];
        if (($category_id >= 0) && ($subcategory_id >= 0))
            sqlStatement("INSERT INTO " . mitigateSqlTableUpperCase("form_CAMOS_item") . " (user, item, content, subcategory_id) values (?, ?, ?, ?)", array($_SESSION['authUser'], $item, $content, $subcategory_id));
    } elseif ($_POST['hidden_selection'] == 'change_content') {
        $item_id = $_POST['hidden_item'];
        if ($item_id >= 0) {
            if ($_POST['hidden_mode'] == 'add to') {
                $tmp = sqlQuery("SELECT content from " . mitigateSqlTableUpperCase("form_CAMOS_item") . " where id = ?", array($item_id));
                if (isset($tmp)) $content .= "\n" . $tmp['content'];
            }
            sqlStatement("UPDATE " . mitigateSqlTableUpperCase("form_CAMOS_item") . " set content = ? where id = ?", array($content, $item_id));
        }
    }
} elseif ($_POST['hidden_mode'] == 'delete') {
    if ($delete_subdata) {
        if ($_POST['hidden_selection'] == 'change_category') {
            $to_delete_id = $_POST['hidden_category'];
            $s1 = sqlStatement("select id from " . mitigateSqlTableUpperCase("form_CAMOS_subcategory") . " where category_id = ?", array($to_delete_id));
            while ($r1 = sqlFetchArray($s1))
                sqlStatement("DELETE FROM " . mitigateSqlTableUpperCase("form_CAMOS_item") . " WHERE subcategory_id = ?", array($r1['id']));
            sqlStatement("DELETE FROM " . mitigateSqlTableUpperCase("form_CAMOS_subcategory") . " WHERE category_id = ?", array($to_delete_id));
            sqlStatement("DELETE FROM " . mitigateSqlTableUpperCase("form_CAMOS_category") . " WHERE id = ?", array($to_delete_id));
        } elseif ($_POST['hidden_selection'] == 'change_subcategory') {
            $to_delete_id = $_POST['hidden_subcategory'];
            sqlStatement("DELETE FROM " . mitigateSqlTableUpperCase("form_CAMOS_item") . " WHERE subcategory_id = ?", array($to_delete_id));
            sqlStatement("DELETE FROM " . mitigateSqlTableUpperCase("form_CAMOS_subcategory") . " WHERE id = ?", array($to_delete_id));
        } elseif ($_POST['hidden_selection'] == 'change_item') {
            if ((isset($_POST['select_item'])) && (count($_POST['select_item']) > 1)) {
                foreach ($_POST['select_item'] as $v)
                    sqlStatement("DELETE FROM " . mitigateSqlTableUpperCase("form_CAMOS_item") . " WHERE id = ?", array($v));
            } else {
                sqlStatement("DELETE FROM " . mitigateSqlTableUpperCase("form_CAMOS_item") . " WHERE id = ?", array($_POST['hidden_item']));
            }
        }
    }
} elseif ($_POST['hidden_mode'] == 'alter') {
    $newval = $_POST[$_POST['hidden_selection']];
    if ($_POST['hidden_selection'] == 'change_category') {
        $to_alter_id = $_POST['hidden_category']; $to_alter_table = 'form_CAMOS_category'; $to_alter_column = 'category';
    } elseif ($_POST['hidden_selection'] == 'change_subcategory') {
        $to_alter_id = $_POST['hidden_subcategory']; $to_alter_table = 'form_CAMOS_subcategory'; $to_alter_column = 'subcategory';
    } elseif ($_POST['hidden_selection'] == 'change_item') {
        $to_alter_id = $_POST['hidden_item']; $to_alter_table = 'form_CAMOS_item'; $to_alter_column = 'item';
    }
    sqlStatement("UPDATE " . escape_table_name($to_alter_table) . " set " . escape_sql_column_name($to_alter_column, [$to_alter_table]) . " = ? where id = ?", array($newval, $to_alter_id));
}

$preselect_mode = '';
if ($preselect_category == '' && !$out_of_encounter) {
    $preselect_mode = 'by name';
    $tmp   = sqlQuery("SELECT max(id) AS max FROM " . mitigateSqlTableUpperCase("form_CAMOS") . " WHERE pid = ?", array($_SESSION['pid']));
    $maxid = $tmp['max'] ? $tmp['max'] : 0;
    $statement = sqlStatement("SELECT category, subcategory, item FROM " . mitigateSqlTableUpperCase("form_CAMOS") . " WHERE id = ?", array($maxid));
    if ($result = sqlFetchArray($statement)) {
        $preselect_category    = $result['category'];
        $preselect_subcategory = $result['subcategory'];
        $preselect_item        = $result['item'];
    } else {
        $preselect_mode = '';
    }
} else {
    $preselect_mode = 'by number';
}

// Clone data setup (kept intact from original)
$clone_data_array = array();
if (1) {
    $clone_category = '';
    $clone_subcategory = '';
    $clone_item = '';
    $clone_content = '';
    if (substr($_POST['hidden_mode'] ?? '', 0, 5) == 'clone') {
        $clone_category = $_POST['category'] ? $_POST['category'] : '';
        $clone_category_term = $clone_category != '' ? " where category like '" . add_escape_custom($clone_category) . "'" : '';
        $clone_subcategory = $_POST['subcategory'] ? $_POST['subcategory'] : '';
        $clone_subcategory_term = $clone_subcategory != '' ? " and subcategory like '" . add_escape_custom($_POST['subcategory']) . "'" : '';
        $clone_item = $_POST['item'] ? $_POST['item'] : '';
        $clone_item_term = $clone_item != '' ? " and item like '" . add_escape_custom($_POST['item']) . "'" : '';
        $clone_search = trim($_POST['clone_others_search']);
        $name_data_flag = false;
        $show_phone_flag = false;
        $pid_clause = '';
        if (strpos($clone_search, "::") !== false) {
            $name_data_flag = true; $show_phone_flag = true;
            $split = preg_split('/\s*::\s*/', $clone_search);
            $clone_search = $split[1]; $pid_clause = searchName($split[0]);
        } elseif (strpos($clone_search, ":") !== false) {
            $name_data_flag = true;
            $split = preg_split('/\s*:\s*/', $clone_search);
            $clone_search = $split[1]; $pid_clause = searchName($split[0]);
        }
        $clone_search_term = '';
        if (!empty($clone_search)) {
            $clone_search = preg_replace('/\s+/', '%', $clone_search);
            if (substr($clone_search, 0, 1) == "`") { $clone_subcategory_term = ''; $clone_item_term = ''; $clone_search = substr($clone_search, 1); }
            $clone_search_term = " and content like '%" . add_escape_custom($clone_search) . "%'";
        }
        if (substr($_POST['hidden_mode'] ?? '', 0, 12) == 'clone others') {
            if ($_POST['hidden_mode'] == 'clone others selected') {
                $query = "select id, category, subcategory, item, content from " . mitigateSqlTableUpperCase("form_CAMOS") . " " . $clone_category_term . $clone_subcategory_term . $clone_item_term . $clone_search_term . $pid_clause . " order by id desc limit " . escape_limit($limit);
            } else {
                $query = "select id, category, subcategory, item, content from " . mitigateSqlTableUpperCase("form_CAMOS") . " where content like '%" . add_escape_custom($clone_search) . "%'" . $pid_clause . " order by id desc limit " . escape_limit($limit);
            }
            $statement = sqlStatement($query);
            while ($result = sqlFetchArray($statement)) {
                $tmp = '/*camos::' . $result['category'] . '::' . $result['subcategory'] . '::' . $result['item'] . '::' . $result['content'] . '*/';
                if ($name_data_flag === true) $tmp = getMyPatientData($result['id'], $show_phone_flag) . "\n$break\n" . $tmp;
                $key_tmp = preg_replace('/\W+/', '', $tmp);
                $clone_data_array[$key_tmp] = $tmp;
            }
        } else {
            if ($_POST['hidden_mode'] == 'clone last visit') {
                $stepback = $_POST['stepback'] ? $_POST['stepback'] : 1;
                $tmp = sqlQuery("SELECT max(encounter) as max FROM forms where encounter < ? and form_name like 'CAMOS%' and pid= ?", array($_SESSION['encounter'], $_SESSION['pid']));
                $last_encounter_id = $tmp['max'] ? $tmp['max'] : 0;
                for ($i = 0; $i < $stepback - 1; $i++) {
                    $tmp = sqlQuery("SELECT max(encounter) as max FROM forms where encounter < ? and form_name like 'CAMOS%' and pid= ?", array($last_encounter_id, $_SESSION['pid']));
                    $last_encounter_id = $tmp['max'] ? $tmp['max'] : 0;
                }
                $query = "SELECT category, subcategory, item, content FROM " . mitigateSqlTableUpperCase("form_CAMOS") . " join forms on (" . mitigateSqlTableUpperCase("form_CAMOS") . ".id = forms.form_id) where forms.encounter = ? and " . mitigateSqlTableUpperCase("form_CAMOS") . ".pid=? order by " . mitigateSqlTableUpperCase("form_CAMOS") . ".id";
                $statement = sqlStatement($query, array($last_encounter_id, $_SESSION['pid']));
            } else {
                $query = "SELECT date(date) as date, subcategory, item, content FROM " . mitigateSqlTableUpperCase("form_CAMOS") . " WHERE category like ? and pid=? order by id desc";
                $statement = sqlStatement($query, array($clone_category, $_SESSION['pid']));
            }
            while ($result = sqlFetchArray($statement)) {
                if (preg_match('/^[\s\r\n]*$/', $result['content']) == 0) {
                    if ($_POST['hidden_mode'] == 'clone last visit') $clone_category = $result['category'];
                    $clone_subcategory = $result['subcategory']; $clone_item = $result['item']; $clone_content = $result['content'];
                    $clone_data1 = "/* camos :: $clone_category :: $clone_subcategory :: $clone_item :: ";
                    $clone_data2 = "$clone_content */";
                    $clone_data3 = $clone_data1 . $clone_data2;
                    if ($_POST['hidden_mode'] == 'clone last visit') $clone_data1 = $clone_data3;
                    if (!$clone_data_array[$clone_data1]) {
                        $clone_data_array[$clone_data1] = "";
                        if ($_POST['hidden_mode'] == 'clone') $clone_data_array[$clone_data1] = "/* ------  " . $result['date'] . "  --------- */\n";
                        $clone_data_array[$clone_data1] .= $clone_data3;
                    }
                }
            }
        }
    }
}
?>
<html>
<head>
<?php Header::setupHeader(); ?>
<style>
:root {
    --accent: #47377a;
    --accent2: #7c3aed;
    --border: #e4ddf5;
    --surface: #ffffff;
    --bg: #f3f0f9;
    --red: #dc2626;
    --blue: #3a5a9a;
    --green: #059669;
    --muted: #7c6f96;
}

* { box-sizing: border-box; }

body {
    background: var(--bg) !important;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: 13px;
    margin: 0;
    padding: 0;
}

/* ── Top toolbar ── */
#camos-toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    padding: 10px 14px;
    background: #fff;
    border-bottom: 1px solid var(--border);
    border-radius: 12px 12px 0 0;
    margin: 12px 12px 0 12px;
}

#camos-toolbar .toolbar-group {
    display: flex;
    align-items: center;
    gap: 6px;
}

#camos-toolbar .divider {
    width: 1px;
    height: 24px;
    background: var(--border);
    margin: 0 4px;
}

/* ── Buttons ── */
.cb {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 13px;
    border-radius: 7px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all .15s;
    white-space: nowrap;
    font-family: inherit;
}

.cb-primary {
    background: linear-gradient(135deg, #5b21b6, #7c3aed);
    color: #fff;
    border-color: transparent;
    box-shadow: 0 3px 10px rgba(124,58,237,.25);
}
.cb-primary:hover { opacity: .9; transform: translateY(-1px); }

.cb-outline {
    background: #fff;
    color: var(--accent);
    border-color: var(--border);
}
.cb-outline:hover { background: var(--bg); border-color: #c4b8e8; }

.cb-danger {
    background: #fff;
    color: var(--red);
    border-color: #fecaca;
}
.cb-danger:hover { background: #fef2f2; }

.cb-blue {
    background: #eff6ff;
    color: var(--blue);
    border-color: #bfdbfe;
}
.cb-blue:hover { background: #dbeafe; }

.cb-green {
    background: #f0fdf4;
    color: var(--green);
    border-color: #bbf7d0;
}
.cb-green:hover { background: #dcfce7; }

.cb-sm {
    padding: 3px 9px;
    font-size: 11px;
    border-radius: 5px;
}

/* ── Main 3-column layout ── */
#camos-main {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr 2fr;
    gap: 0;
    background: #fff;
    border: 1px solid var(--border);
    border-top: none;
    margin: 0 12px;
    border-radius: 0 0 12px 12px;
    overflow: hidden;
    box-shadow: 0 4px 18px rgba(71,55,122,.08);
}

.col-panel {
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
}
.col-panel:last-child { border-right: none; }

.col-header {
    background: var(--bg);
    padding: 8px 12px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--accent);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.col-select {
    flex: 1;
    border: none;
    outline: none;
    width: 100%;
    padding: 4px 0;
    font-size: 13px;
    font-family: inherit;
    background: #fff;
    color: #222;
    cursor: pointer;
    min-height: 320px;
}

.col-select option {
    padding: 6px 12px;
    border-bottom: 1px solid #f5f3ff;
}
.col-select option:hover,
.col-select option:checked {
    background: linear-gradient(135deg, #ede9fe, #ddd6fe) !important;
    color: var(--accent);
}

.col-footer {
    padding: 8px 10px;
    border-top: 1px solid var(--border);
    background: #faf8ff;
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
    align-items: center;
}

.col-footer input[type="text"] {
    flex: 1;
    min-width: 60px;
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 4px 8px;
    font-size: 12px;
    font-family: inherit;
    background: #fff;
}
.col-footer input[type="text"]:focus {
    outline: none;
    border-color: var(--accent2);
    box-shadow: 0 0 0 3px rgba(124,58,237,.1);
}

/* ── Content panel ── */
#content-panel {
    display: flex;
    flex-direction: column;
}

#content-panel textarea {
    flex: 1;
    border: none;
    outline: none;
    resize: none;
    padding: 14px 16px;
    font-size: 13px;
    font-family: inherit;
    color: #222;
    line-height: 1.7;
    min-height: 320px;
    background: #fff;
}
#content-panel textarea:focus { background: #fefcff; }

.content-footer {
    padding: 10px 12px;
    border-top: 1px solid var(--border);
    background: #faf8ff;
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

.search-bar {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    border-top: 1px solid var(--border);
    background: #faf8ff;
}

.search-bar input[type="text"] {
    flex: 1;
    border: 1px solid var(--border);
    border-radius: 7px;
    padding: 6px 10px;
    font-size: 12px;
    font-family: inherit;
    background: #fff;
}
.search-bar input[type="text"]:focus {
    outline: none;
    border-color: var(--accent2);
    box-shadow: 0 0 0 3px rgba(124,58,237,.1);
}

/* ── Info bar ── */
#id_info {
    margin: 6px 12px 0;
    font-size: 12px;
    color: var(--muted);
}

/* ── Stepback select ── */
#stepback-select {
    border: 1px solid var(--border);
    border-radius: 7px;
    padding: 5px 8px;
    font-size: 12px;
    font-family: inherit;
    background: #fff;
    color: var(--accent);
}

/* ── Append toggle ── */
#append-toggle {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--muted);
    cursor: pointer;
    user-select: none;
    padding: 4px 8px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: #fff;
}
#append-toggle input { accent-color: var(--accent2); cursor: pointer; }
#append-toggle.active { background: #f5f3ff; border-color: #c4b8e8; color: var(--accent); font-weight: 600; }

/* Scrollbar styling */
.col-select::-webkit-scrollbar,
#content-panel textarea::-webkit-scrollbar { width: 4px; }
.col-select::-webkit-scrollbar-track,
#content-panel textarea::-webkit-scrollbar-track { background: transparent; }
.col-select::-webkit-scrollbar-thumb,
#content-panel textarea::-webkit-scrollbar-thumb { background: #d8d0f0; border-radius: 4px; }
</style>

<script>
var array1 = new Array();
var array2 = new Array();
var array3 = new Array();
var buffer = new Array();
var icd10_list = '';
var preselect_off = false;
var content_change_flag = false;
var lock_override_flag = false;
var columns_status = 'show';
var clone_mode = false;
var crop_buffer = '';
var special_select_start = 0;
var special_select_end = 0;
var appendMode = true; // DEFAULT: append instead of clear

<?php if (substr($_POST['hidden_mode'] ?? '', 0, 5) == 'clone') echo "clone_mode = true;\n"; ?>

<?php
if (!$out_of_encounter) {
    $code_list = '';
    $query = "SELECT code_text, code FROM billing WHERE encounter=? AND pid=? AND code_type like 'ICD10' AND activity=1";
    $statement = sqlStatement($query, array($_SESSION['encounter'], $_SESSION['pid']));
    if ($result = sqlFetchArray($statement)) {
        $code_list = "\n\n" . trim(preg_replace('/\r\n|\r|\n/', '', text($result['code'] . " " . $result['code_text'])));
    }
    while ($result = sqlFetchArray($statement)) {
        $code_list .= "\n\n" . trim(preg_replace('/\r\n|\r|\n/', '', text($result['code'] . " " . $result['code_text'])));
    }
    $code_list = "icd10_list=" . js_escape($code_list . "\n") . ";\n";
    if (!empty($code_list)) echo $code_list;
}

$query = "SELECT id, category FROM " . mitigateSqlTableUpperCase("form_CAMOS_category") . " ORDER BY category";
$statement = sqlStatement($query);
$i = 0;
while ($result = sqlFetchArray($statement)) {
    echo "array1[" . attr(addslashes($i)) . "] = new Array(\"" . fixquotes($result['category']) . "\"," . js_escape($result['id']) . ", new Array());\n";
    $i++;
}

$i = 0;
$query = "SELECT id, subcategory, category_id FROM " . mitigateSqlTableUpperCase("form_CAMOS_subcategory") . " ORDER BY subcategory";
$statement = sqlStatement($query);
while ($result = sqlFetchArray($statement)) {
    echo "array2[" . attr(addslashes($i)) . "] = new Array(\"" . fixquotes($result['subcategory']) . "\", " . js_escape($result['category_id']) . ", " . js_escape($result['id']) . ", new Array());\n";
    $i++;
}

$i = 0;
$query = "SELECT id, item, content, subcategory_id FROM " . mitigateSqlTableUpperCase("form_CAMOS_item") . " ORDER BY item";
$statement = sqlStatement($query);
while ($result = sqlFetchArray($statement)) {
    echo "array3[" . attr(addslashes($i)) . "] = new Array(\"" . fixquotes($result['item']) . "\", \"" . fixquotes(str_replace($quote_search_content, $quote_replace_content, strip_tags($result['content'], "<b>,<i>"))) . "\", " . js_escape($result['subcategory_id']) . "," . js_escape($result['id']) . ");\n";
    $i++;
}
?>

// ── Append/Replace toggle ────────────────────────────
function toggleAppend() {
    appendMode = !appendMode;
    var tog = document.getElementById('append-toggle');
    var lbl = document.getElementById('append-label');
    if (appendMode) {
        tog.classList.add('active');
        lbl.textContent = 'Append Mode ON';
    } else {
        tog.classList.remove('active');
        lbl.textContent = 'Append Mode OFF';
    }
}

function trimString(str) {
    str = this != window ? this : str;
    return str.replace(/^\s+/g, '').replace(/\s+$/g, '');
}
function isLocked() {
    var f2 = document.CAMOS;
    if (lock_override_flag) { lock_override_flag = false; return false; }
    return /\/\*\s*lock\s*\:\:\s*\*\//.test(f2.textarea_content.value);
}
function lock_content() {
    var f2 = document.CAMOS;
    if ((trimString(f2.textarea_content.value) != "") && (!isLocked())) {
        f2.textarea_content.value = f2.textarea_content.value + "\n\n" + "/*lock::*/";
        lock_override_flag = true;
        js_button('add', 'change_content');
    }
}
function allSelected() {
    var f2 = document.CAMOS;
    return !((f2.select_category.selectedIndex < 0) || (f2.select_subcategory.selectedIndex < 0) || (f2["select_item[]"].selectedIndex < 0));
}
function content_focus() {
    if (!content_change_flag) { content_change_flag = true; }
}
function content_blur() { content_change_flag = false; }
function append_icd10() {
    document.CAMOS.textarea_content.value += icd10_list;
}
function specialSelect(t_area, delim_1, delim_2) {
    if (crop_buffer != '') { t_area.value = crop_buffer; crop_buffer = ''; return; }
    var cv = t_area.value;
    var start = t_area.selectionStart;
    var end = t_area.selectionEnd;
    var newstart = cv.lastIndexOf(delim_1, start);
    var newend = cv.indexOf(delim_2, end);
    if ((newstart == -1) || (newend == -1)) return;
    if ((newstart == special_select_start) && (newend == special_select_end)) cropToSelect(t_area, delim_2);
    if (newstart >= 0 && newend >= 0) { t_area.selectionStart = newstart; t_area.selectionEnd = newend + delim_2.length; }
    special_select_start = newstart; special_select_end = newend;
}
function cropToSelect(t_area, delim_2) {
    var cv = t_area.value; crop_buffer = cv;
    var start = special_select_start; var end = special_select_end + delim_2.length;
    t_area.value = cv.substr(start, end - start);
}

function select_word(mode, mystring, myselect) {
    if (preselect_off) return 0;
    for (var i = 0; i < myselect.length; i++) {
        var match = mode == 'by name' ? myselect.options[i].text : (mode == 'by number' ? myselect.options[i].value : '');
        if (match == mystring) myselect.selectedIndex = i;
    }
    return 1;
}

function selectContains(myselect, str) {
    for (var i = 0; i < myselect.length; i++) {
        if (myselect.options[i].text == trimString(str)) return true;
    }
}

function init() {
    var f2 = document.CAMOS;
    if (clone_mode) clone_mode = false;
    for (var i1 = 0; i1 < array1.length; i1++) {
        f2.select_category.options[f2.select_category.length] = new Option(array1[i1][0], array1[i1][1]);
    }
<?php
$temp_preselect_mode = $preselect_mode;
if ($preselect_category_override != '') { $temp_preselect_mode = "by name"; $preselect_category = $preselect_category_override; }
?>
    if (select_word("<?php echo fixquotes($temp_preselect_mode) . "\", \"" . fixquotes($preselect_category); ?>", f2.select_category)) {
        click_category();
    }
<?php
if (substr($_POST['hidden_mode'] ?? '', 0, 5) == 'clone') {
    echo "f2.textarea_content.value = '';\n";
    echo "f2.textarea_content.value += '/* count = " . count($clone_data_array) . "*/\\n$break\\n';";
    foreach ($clone_data_array as $key => $val) {
        echo "f2.textarea_content.value = f2.textarea_content.value + \"" . fixquotes(str_replace($quote_search, $quote_replace, $val)) . "\\n$break\\n\"\n";
    }
}
?>
    // Set append toggle visual state
    var tog = document.getElementById('append-toggle');
    if (appendMode) tog.classList.add('active');
}

function click_category() {
    var f2 = document.CAMOS;
    var category_index = f2.select_category.selectedIndex;
    if ((category_index < 0) || (category_index > f2.select_category.length - 1)) return 0;
    var sel = f2.select_category.options[f2.select_category.selectedIndex].value;
    for (var i1 = 0; i1 < array1.length; i1++) {
        if (array1[i1][1] == sel) {
            f2.select_subcategory.length = 0;
            f2["select_item[]"].length = 0;
            // Only clear if NOT in append mode
            if (!appendMode) f2.textarea_content.value = '';
            for (var i2 = 0; i2 < array2.length; i2++) {
                if (array1[i1][1] == array2[i2][1]) {
                    f2.select_subcategory.options[f2.select_subcategory.length] = new Option(array2[i2][0], array2[i2][2]);
                }
            }
        }
    }
<?php
$temp_preselect_mode = $preselect_mode;
if ($preselect_subcategory_override != '') { $temp_preselect_mode = "by name"; $preselect_subcategory = $preselect_subcategory_override; }
?>
    if (select_word("<?php echo fixquotes($temp_preselect_mode) . "\", \"" . fixquotes($preselect_subcategory); ?>", f2.select_subcategory)) {
        click_subcategory();
    }
}

function click_subcategory() {
    var f2 = document.CAMOS;
    var subcategory_index = f2.select_subcategory.selectedIndex;
    if ((subcategory_index < 0) || (subcategory_index > f2.select_subcategory.length - 1)) return 0;
    var sel = f2.select_subcategory.options[f2.select_subcategory.selectedIndex].value;
    for (var i1 = 0; i1 < array2.length; i1++) {
        if (array2[i1][2] == sel) {
            f2["select_item[]"].length = 0;
            // Only clear if NOT in append mode
            if (!appendMode) f2.textarea_content.value = '';
            for (var i2 = 0; i2 < array3.length; i2++) {
                if (array2[i1][2] == array3[i2][2]) {
                    f2["select_item[]"].options[f2["select_item[]"].length] = new Option(array3[i2][0], array3[i2][3]);
                }
            }
        }
    }
<?php
$temp_preselect_mode = $preselect_mode;
if ($preselect_item_override != '') { $temp_preselect_mode = "by name"; $preselect_item = $preselect_item_override; }
?>
    if (select_word("<?php echo fixquotes($temp_preselect_mode) . "\", \"" . fixquotes($preselect_item); ?>", f2["select_item[]"])) {
        click_item();
        preselect_off = true;
    }
}

function click_item() {
    var f2 = document.CAMOS;
    var item_index = f2["select_item[]"].selectedIndex;
    if ((item_index < 0) || (item_index > f2["select_item[]"].length - 1)) return 0;
    var sel = f2["select_item[]"].options[item_index].value;
    for (var i1 = 0; i1 < array3.length; i1++) {
        if (array3[i1][3] == sel) {
            var newContent = array3[i1][1].replace(/\\/g, '');
            if (appendMode && f2.textarea_content.value.trim() !== '') {
                // Append with separator
                f2.textarea_content.value += '\n\n— ' + f2["select_item[]"].options[item_index].text + ' —\n' + newContent;
            } else {
                f2.textarea_content.value = newContent;
            }
        }
    }
}

function clearContent() {
    if (confirm('Clear the content box?')) {
        document.CAMOS.textarea_content.value = '';
    }
}

// AJAX
function getxmlhttp() {
    var xmlhttp = false;
    try { xmlhttp = new ActiveXObject("Msxml2.XMLHTTP"); } catch (e) {
        try { xmlhttp = new ActiveXObject("Microsoft.XMLHTTP"); } catch (E) { xmlhttp = false; }
    }
    if (!xmlhttp && typeof XMLHttpRequest != 'undefined') xmlhttp = new XMLHttpRequest();
    return xmlhttp;
}
function processajax(serverPage, obj, getOrPost, str) {
    xmlhttp = getxmlhttp();
    if (getOrPost == "get") {
        xmlhttp.open("GET", serverPage);
        xmlhttp.onreadystatechange = function() { if (xmlhttp.readyState == 4 && xmlhttp.status == 200) obj.innerHTML = xmlhttp.responseText; }
        xmlhttp.send(null);
    } else {
        xmlhttp.open("POST", serverPage, true);
        xmlhttp.setRequestHeader("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8");
        xmlhttp.onreadystatechange = function() { if (xmlhttp.readyState == 4 && xmlhttp.status == 200) obj.innerHTML = xmlhttp.responseText; }
        xmlhttp.send(str);
    }
}
function setformvalues(form_array) {
    var str = '';
    for (key in form_array) str += key + "=" + encodeURIComponent(form_array[key]) + "&";
    return str;
}

function js_button(mode, selection) {
    var f2 = document.CAMOS;
    if ((mode == 'add') && (selection == 'change_content') && (isLocked())) {
        alert(<?php echo xlj("You have attempted to alter content which is locked. Remove the lock if you want to do this."); ?>);
        return;
    }
    if ((mode == 'add') || (mode == 'alter')) {
        if (selection == 'change_category' && trimString(f2.change_category.value) == "") { alert(<?php echo xlj("You cannot add a blank value for a category!"); ?>); return; }
        if (selection == 'change_subcategory' && trimString(f2.change_subcategory.value) == "") { alert(<?php echo xlj("You cannot add a blank value for a subcategory!"); ?>); return; }
        if (selection == 'change_item' && trimString(f2.change_item.value) == "") { alert(<?php echo xlj("You cannot add a blank value for an item!"); ?>); return; }
    }
    if (mode == 'delete' && !confirm(<?php echo xlj("Are you sure you want to delete this item from the database?"); ?>)) return;

    var category_index = f2.select_category.selectedIndex;
    var category_value = category_index < 0 ? -1 : f2.select_category.options[category_index].value;
    var category_text  = category_index < 0 ? '' : f2.select_category.options[category_index].text;
    var subcategory_index = f2.select_subcategory.selectedIndex;
    var subcategory_value = subcategory_index < 0 ? -1 : f2.select_subcategory.options[subcategory_index].value;
    var subcategory_text  = subcategory_index < 0 ? '' : f2.select_subcategory.options[subcategory_index].text;
    var item_index = f2["select_item[]"].selectedIndex;
    var item_value = item_index < 0 ? -1 : f2["select_item[]"].options[item_index].value;
    var item_text  = item_index < 0 ? '' : f2["select_item[]"].options[item_index].text;

    f2.category.value    = category_text;
    f2.subcategory.value = subcategory_text;
    f2.item.value        = item_text;

    if ((mode.substr(0, 5) == 'clone') || (mode == 'add') || (mode == 'add to') || (mode == 'alter') || (mode == 'delete')) {
        f2.hidden_mode.value       = mode;
        f2.hidden_selection.value  = selection;
        f2.hidden_category.value   = category_value;
        f2.hidden_subcategory.value= subcategory_value;
        f2.hidden_item.value       = item_value;
<?php if (!$out_of_encounter): ?>
        f2.action = '<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/encounter/load_form.php?formname=CAMOS';
<?php else: ?>
        f2.action = '<?php echo $GLOBALS['webroot']; ?>/interface/forms/CAMOS/new.php?mode=external';
<?php endif; ?>
        f2.target = '_self';
        f2.submit();
        return;
    }
    // AJAX submit
    var myobj = document.getElementById('id_info');
    var myarray = {};
    myarray['category']   = category_text;
    myarray['subcategory']= subcategory_text;
    myarray['item']       = item_text;
    myarray['content']    = (selection == 'submit_selection') ?
        (f2.textarea_content.value).substring(f2.textarea_content.selectionStart, f2.textarea_content.selectionEnd) :
        f2.textarea_content.value;
    myarray['csrf_token_form'] = <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>;
    processajax('<?php echo $GLOBALS['webroot']; ?>/interface/forms/CAMOS/ajax_save.php', myobj, "post", setformvalues(myarray));
}

function getKey(e) { return window.event ? e.keyCode : (e.which ? e.which : 0); }
function gotoOne(e) { if (getKey(e) == 96) document.CAMOS.clone_others_search.focus(); }
function processEnter(e, message) {
    if (getKey(e) == 13 && message == "clone_others_search") js_button('clone others', 'clone others');
}

$(function() { init(); });
</script>
</head>

<body onKeyPress="gotoOne(event)">

<form method='post' action="<?php echo $rootdir; ?>/forms/CAMOS/save.php?mode=new" name="CAMOS">
<input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
<input type='hidden' name='hidden_mode' />
<input type='hidden' name='hidden_selection' />
<input type='hidden' name='hidden_category' />
<input type='hidden' name='hidden_subcategory' />
<input type='hidden' name='hidden_item' />
<input type='hidden' name='category' />
<input type='hidden' name='subcategory' />
<input type='hidden' name='item' />
<input type='hidden' name='content' />

<!-- ── Toolbar ── -->
<div id="camos-toolbar">
<?php if (!$out_of_encounter): ?>
    <div class="toolbar-group">
        <button type="button" class="cb cb-outline" onclick="js_button('clone','clone')">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            Clone
        </button>
        <button type="button" class="cb cb-outline" onclick="js_button('clone last visit','clone last visit')">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.24"/></svg>
            Clone Past Visit
        </button>
        <select name='stepback' id="stepback-select">
            <?php for ($i = 1; $i <= 12; $i++): ?>
            <option value='<?php echo $i; ?>'>Back <?php echo $i; ?> visit<?php echo $i > 1 ? 's' : ''; ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="divider"></div>
    <div class="toolbar-group">
        <button type="button" class="cb cb-primary" onclick="js_button('submit','submit_selection')">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            Submit Selected
        </button>
        <button type="button" class="cb cb-primary" onclick="js_button('submit','submit')">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            Submit All
        </button>
    </div>
    <div class="divider"></div>
<?php endif; ?>

    <!-- Append toggle -->
    <label id="append-toggle" onclick="toggleAppend()">
        <input type="checkbox" checked onchange="toggleAppend()" style="pointer-events:none;">
        <span id="append-label">Append Mode ON</span>
    </label>

    <button type="button" class="cb cb-danger cb-sm" onclick="clearContent()">
        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
        Clear Content
    </button>

<?php if (!$out_of_encounter): ?>
    <div style="margin-left:auto;">
        <a href="<?php echo $GLOBALS['form_exit_url']; ?>" onclick="top.restoreSession()" class="cb cb-outline cb-sm">Leave Form</a>
    </div>
<?php endif; ?>
</div>

<!-- ── Info area (AJAX output) ── -->
<div id="id_info"></div>

<!-- ── Main grid ── -->
<div id="camos-main">

    <!-- Category -->
    <div class="col-panel">
        <div class="col-header">Category</div>
        <select name='select_category' class="col-select" size='<?php echo attr($select_size); ?>' onchange="click_category()"></select>
        <?php if (myAuth() == 1): ?>
        <div class="col-footer">
            <input type='text' name='change_category' placeholder="New category…">
            <button type="button" class="cb cb-green cb-sm" onclick="js_button('add','change_category')">Add</button>
            <button type="button" class="cb cb-outline cb-sm" onclick="js_button('alter','change_category')">Edit</button>
            <button type="button" class="cb cb-danger cb-sm" onclick="js_button('delete','change_category')">Del</button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Subcategory -->
    <div class="col-panel">
        <div class="col-header">Subcategory</div>
        <select name='select_subcategory' class="col-select" size='<?php echo attr($select_size); ?>' onchange="click_subcategory()"></select>
        <?php if (myAuth() == 1): ?>
        <div class="col-footer">
            <input type='text' name='change_subcategory' placeholder="New subcategory…">
            <button type="button" class="cb cb-green cb-sm" onclick="js_button('add','change_subcategory')">Add</button>
            <button type="button" class="cb cb-outline cb-sm" onclick="js_button('alter','change_subcategory')">Edit</button>
            <button type="button" class="cb cb-danger cb-sm" onclick="js_button('delete','change_subcategory')">Del</button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Item -->
    <div class="col-panel">
        <div class="col-header">Item</div>
        <select name='select_item[]' class="col-select" size='<?php echo attr($select_size); ?>' onchange="click_item()" multiple="multiple"></select>
        <?php if (myAuth() == 1): ?>
        <div class="col-footer">
            <input type='text' name='change_item' placeholder="New item…">
            <button type="button" class="cb cb-green cb-sm" onclick="js_button('add','change_item')">Add</button>
            <button type="button" class="cb cb-outline cb-sm" onclick="js_button('alter','change_item')">Edit</button>
            <button type="button" class="cb cb-danger cb-sm" onclick="js_button('delete','change_item')">Del</button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Content -->
    <div class="col-panel" id="content-panel">
        <div class="col-header">
            Content
            <?php if (myAuth() == 1): ?>
            <div style="display:flex;gap:5px;">
                <button type="button" style="display:none;" class="cb cb-green cb-sm" onclick="js_button('add','change_content')">Save to DB</button>
                <button type="button" style="display:none;" class="cb cb-outline cb-sm" onclick="js_button('add to','change_content')">Add to DB</button>
                <button type="button" class="cb cb-outline cb-sm" onclick="lock_content()">🔒 Lock</button>
                <?php if (!$out_of_encounter): ?>
                <button type="button" class="cb cb-blue cb-sm" onclick="append_icd10()">ICD10</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <textarea name='textarea_content' onFocus="content_focus()" onBlur="content_blur()" onDblClick="specialSelect(this,'/*','*/')"></textarea>
        <!-- Search bar -->
        <div class="search-bar">
            <svg width="13" height="13" fill="none" stroke="#9ca3af" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type='text' name='clone_others_search' value='<?php echo attr($_POST['clone_others_search'] ?? ''); ?>'
                   placeholder="Search content across all patients…"
                   onKeyPress="processEnter(event,'clone_others_search')">
            <button type="button" class="cb cb-outline cb-sm" onclick="js_button('clone others','clone others')">Search</button>
            <button type="button" class="cb cb-outline cb-sm" onclick="js_button('clone others selected','clone others selected')">Search Selected</button>
        </div>
        <?php if (!$out_of_encounter): ?>
        <div class="content-footer">
            <a href="<?php echo $GLOBALS['form_exit_url']; ?>" onclick="top.restoreSession()" class="cb cb-outline cb-sm">← Leave Form</a>
            <a href="<?php echo $GLOBALS['webroot']; ?>/interface/forms/CAMOS/help.html" target="new" class="cb cb-outline cb-sm">Help</a>
        </div>
        <?php endif; ?>
    </div>

</div><!-- end camos-main -->

</form>
</body>
</html>

<?php
formFooter();

function fixquotes($string) {
    $string = preg_replace('/([\\\])*\"/', "\\\"", $string);
    return $string;
}

function searchName($string) {
    $string = trim($string);
    if ($string == 'this') return " and (pid = '" . add_escape_custom($_SESSION['pid']) . "') ";
    global $limit;
    $ret = ''; $data = array();
    if ($string == '') return $ret;
    $split = preg_split('/\s+/', $string);
    $name1 = isset($split[1]) ? "%" . $split[1] . "%" : '';
    $name2 = isset($split[0]) ? "%" . $split[0] . "%" : '';
    $query = sqlStatement("select pid from patient_data where fname like ? or fname like ? or lname like ? or lname like ? limit " . escape_limit($limit), array($name1, $name2, $name1, $name2));
    while ($results = sqlFetchArray($query)) array_push($data, "'" . add_escape_custom($results['pid']) . "'");
    if (count($data) > 0) { $ret = join(" or pid = ", $data); $ret = " and (pid = " . $ret . ") "; }
    return $ret;
}

function getMyPatientData($form_id, $show_phone_flag) {
    $ret = '';
    $query = sqlStatement("select t1.pid, t1.fname, t1.mname, t1.lname, t1.phone_home, t1.phone_biz, t1.phone_contact, t1.phone_cell, date_format(t1.DOB,'%m-%d-%y') as DOB, date_format(t2.date,'%m-%d-%y') as date, datediff(current_date(),t2.date) as days from patient_data as t1 join forms as t2 on (t1.pid = t2.pid) where t2.form_id=? and form_name like 'CAMOS%'", array($form_id));
    if ($results = sqlFetchArray($query)) {
        $mname = $results['mname'];
        $name  = $results['fname'] . ($mname ? ' ' . $mname : '') . ' ' . $results['lname'];
        $ret   = "/*" . $results['pid'] . ", $name, DOB: " . $results['DOB'] . ", Enc: " . $results['date'] . ", " . $results['days'] . " days ago. */";
        if ($show_phone_flag) $ret .= "\n/* Home: " . $results['phone_home'] . " | Cell: " . $results['phone_cell'] . " | Bus: " . $results['phone_biz'] . " | Contact: " . $results['phone_contact'] . " */";
    }
    return $ret;
}
?>
