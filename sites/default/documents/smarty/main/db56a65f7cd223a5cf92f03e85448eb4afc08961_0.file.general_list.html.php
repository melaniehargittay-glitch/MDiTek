<?php
/* Smarty version 4.3.1, created on 2026-05-31 06:48:13
  from '/var/www/html/templates/pharmacies/general_list.html' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.3.1',
  'unifunc' => 'content_6a1c11ed2272b3_26025309',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    'db56a65f7cd223a5cf92f03e85448eb4afc08961' => 
    array (
      0 => '/var/www/html/templates/pharmacies/general_list.html',
      1 => 1682485781,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
),false)) {
function content_6a1c11ed2272b3_26025309 (Smarty_Internal_Template $_smarty_tpl) {
$_smarty_tpl->_checkPlugins(array(0=>array('file'=>'/var/www/html/library/smarty/plugins/function.xlt.php','function'=>'smarty_function_xlt',),));
?>
<a href="<?php echo $_smarty_tpl->tpl_vars['CURRENT_ACTION']->value;?>
action=edit" onclick="top.restoreSession()" class="btn btn-secondary btn-add" >
<?php echo smarty_function_xlt(array('t'=>'Add a Pharmacy'),$_smarty_tpl);?>
</a><br /><br />
<p><?php echo smarty_function_xlt(array('t'=>'Total pharmacies'),$_smarty_tpl);?>
 <?php if (!empty($_smarty_tpl->tpl_vars['totalpages']->value)) {
echo $_smarty_tpl->tpl_vars['totalpages']->value;
}?></p>
<div class="table-responsive datatable">
	<table class="table table-striped" id="pharmacies">
		<thead>
	        <tr>
	            <th><?php echo smarty_function_xlt(array('t'=>'Name'),$_smarty_tpl);?>
</th>
	            <th><?php echo smarty_function_xlt(array('t'=>'Address'),$_smarty_tpl);?>
</th>
	            <th><?php echo smarty_function_xlt(array('t'=>'Default Method'),$_smarty_tpl);?>
</th>
	        </tr>
	    </thead>
	    <tbody>
		<?php
$_from = $_smarty_tpl->smarty->ext->_foreach->init($_smarty_tpl, $_smarty_tpl->tpl_vars['pharmacies']->value, 'pharmacy');
$_smarty_tpl->tpl_vars['pharmacy']->do_else = true;
if ($_from !== null) foreach ($_from as $_smarty_tpl->tpl_vars['pharmacy']->value) {
$_smarty_tpl->tpl_vars['pharmacy']->do_else = false;
?>
		<tr>
			<td>
			    <a href="<?php echo $_smarty_tpl->tpl_vars['CURRENT_ACTION']->value;?>
action=edit&id=<?php echo attr_url($_smarty_tpl->tpl_vars['pharmacy']->value->id);?>
" onclick="top.restoreSession()">
			        <?php echo text($_smarty_tpl->tpl_vars['pharmacy']->value->name);?>

			    </a>
			</td>
			<td>
			<?php if ($_smarty_tpl->tpl_vars['pharmacy']->value->address->line1 != '') {
echo text($_smarty_tpl->tpl_vars['pharmacy']->value->address->line1);?>
, <?php }?>
			<?php if ($_smarty_tpl->tpl_vars['pharmacy']->value->address->city != '') {
echo text($_smarty_tpl->tpl_vars['pharmacy']->value->address->city);?>
, <?php }?>
				<?php echo text(mb_strtoupper((string) $_smarty_tpl->tpl_vars['pharmacy']->value->address->state ?? '', 'UTF-8'));?>
 <?php echo text($_smarty_tpl->tpl_vars['pharmacy']->value->address->zip);?>
&nbsp;</td>
			<td><?php echo text($_smarty_tpl->tpl_vars['pharmacy']->value->get_transmit_method_display());?>
&nbsp;
		<?php
}
if ($_smarty_tpl->tpl_vars['pharmacy']->do_else) {
?></td>
		</tr>

		<tr>
			<td><?php echo smarty_function_xlt(array('t'=>'No Pharmacies Found'),$_smarty_tpl);?>
</td>
            <td></td>
            <td></td>
		</tr>
		<?php
}
$_smarty_tpl->smarty->ext->_foreach->restore($_smarty_tpl, 1);?>
	    </tbody>
	</table>
</div>
<?php }
}
