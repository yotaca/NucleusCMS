<?

/**
  * Nucleus: PHP/MySQL Weblog CMS (http://nucleuscms.org/) 
  * Copyright (C) 2002 The Nucleus Group
  *
  * This program is free software; you can redistribute it and/or
  * modify it under the terms of the GNU General Public License
  * as published by the Free Software Foundation; either version 2
  * of the License, or (at your option) any later version.
  * (see nucleus/documentation/index.html#license for more info)
  *
  * This script allows adding items to Nucleus through bookmarklets. The member must be logged in
  * in order to use this.
  */

// bookmarklet is part of admin area (might need XML-RPC)
$CONF['UsingAdminArea'] = 1;

// include all classes and config data 
include('../config.php');

if (!$member->isLoggedIn()) {
	bm_loginAndPassThrough();
	exit;
}

$action = requestVar('action');

// on successfull login
if (($action == 'login') && ($member->isLoggedIn()))
	$action = requestVar('nextaction');

// find out what to do
switch ($action) {
	case 'additem':
		bm_doAddItem();		// adds the item for real
		break;
	case 'edit':
		bm_doEditForm();	// shows the edit item form
		break;
	case 'edititem':		// edits the item for real
		bm_doEditItem();
		break;
	case 'login':			// on login, 'action' gets changed to 'nextaction'
		bm_doError('Something went wrong');
		break;
	default:
		bm_doShowForm();	// shows the fill in form
		break;
}
	
function bm_doAddItem() {
	global $member, $manager;
	
	$manager->loadClass('ITEM');
	$result = ITEM::createFromRequest();
	
	if ($result['status'] == 'error')
		bm_doError($result['message']);

	$blogid = getBlogIDFromItemID($result['itemid']);
	$blog =& $manager->getBlog($blogid);
	
	if ($result['status'] == 'newcategory') {
		$message = 'Item was added, and a new category was created. <a href="index.php?action=categoryedit&amp;blogid='.$blogid.'&amp;catid='.$result['catid'].'" onclick="if (event &amp;&amp; event.preventDefault) event.preventDefault(); window.open(this.href); return false;" title="Opens in new window">Click here to edit the name and description of the category.</a>';
		$extrahead = '';
	} elseif ((postVar('actiontype') == 'addnow') && $blog->pingUserland()) {
		$message = 'Item was added successfully. Now pinging weblogs.com. Please hold on... (can take a while)';
		$extrahead = '<meta http-equiv="refresh" content="1; url=index.php?action=sendping&amp;blogid=' . $blogid . '" />';
	} else {
		$message = _ITEM_ADDED;
		$extrahead = '';
	}
	
	bm_message(_ITEM_ADDED, _ITEM_ADDED, $message,$extrahead);
}

function bm_doEditItem() {
	global $member, $manager, $CONF;
	
	$itemid 	= intRequestVar('itemid');
	$catid 		= postVar('catid');
	
	// only allow if user is allowed to alter item
	if (!$member->canUpdateItem($itemid, $catid))
		bm_doError(_ERROR_DISALLOWED);

	$body 		= postVar('body');
	$title 		= postVar('title');
	$more 		= postVar('more');
	$closed 	= intPostVar('closed');
	$actiontype = postVar('actiontype');
	
	// redirect to admin area on delete (has delete confirmation)
	if ($actiontype == 'delete') {
		header('Location: index.php?action=itemdelete&itemid='.$itemid);
		exit;	
	}
	
	// create new category if needed (only on edit/changedate)
	if (strstr($catid,'newcat')) {
		// get blogid 
		list($blogid) = sscanf($catid,"newcat-%d");

		// create
		$blog =& $manager->getBlog($blogid);
		$catid = $blog->createNewCategory();

		// show error when sth goes wrong
		if (!$catid) 
			bm_doError('Could not create new category');
	} 

	// only edit action is allowed for bookmarklet edit
	switch ($actiontype) {
		case 'changedate':
			$publish = 1;
			$wasdraft = 0;
			$timestamp = mktime(postVar('hour'), postVar('minutes'), 0, postVar('month'), postVar('day'), postVar('year'));
			break;
		case 'edit':
			$publish = 1;
			$wasdraft = 0;
			$timestamp = 0;
			break;
		default:
			bm_doError('Something went wrong');
	}
	
	// update item for real
	ITEM::update($itemid, $catid, $title, $body, $more, $closed, $wasdraft, $publish, $timestamp);
	
	// show success message
	if ($catid != intPostVar('catid'))
		bm_message(_ITEM_UPDATED, _ITEM_UPDATED, 'Item was added, and a new category was created. <a href="index.php?action=categoryedit&amp;blogid='.$blog->getID().'&amp;catid='.$catid.'" onclick="if (event &amp;&amp; event.preventDefault) event.preventDefault(); window.open(this.href); return false;" title="Opens in new window">Click here to edit the name and description of the category.</a>', '');
	else
		bm_message(_ITEM_UPDATED, _ITEM_UPDATED, _ITEM_UPDATED, '');
}

function bm_loginAndPassThrough() {

	$blogid = intRequestVar('blogid');
	$log_text = requestVar('logtext');
	$log_link = requestVar('loglink');
	$log_linktitle = requestVar('loglinktitle');
	
	?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
	<html>
	<head>
		<title>Nucleus</title>
		<? bm_style(); ?>
	</head>
	<body>
	<h1><?=_LOGIN_PLEASE?></h1>
	
	<form method="post" action="bookmarklet.php">
	<p>
		<input name="action" value="login" type="hidden" />
		<input name="blogid" value="<?= htmlspecialchars($blogid) ?>" type="hidden" />
		<input name="logtext" value="<?= htmlspecialchars($log_text) ?>" type="hidden" />
		<input name="loglink" value="<?= htmlspecialchars($log_link) ?>" type="hidden" />
		<input name="loglinktitle" value="<?= htmlspecialchars($log_linktitle) ?>" type="hidden" />
		<?=_LOGINFORM_NAME?>:
		<br /><input name="login" />
		<br /><?=_LOGINFORM_PWD?>:
		<br /><input name="password" type="password" />
		<br /><br />
		<br /><input type="submit" value="<?=_LOGIN?>" />
	</p>
	</form>
	<p><a href="bookmarklet.php" onclick="window.close();"><?=_POPUP_CLOSE?></a></p>
	</body>
	</html>
	<?

}

function bm_doShowForm() {
	global $member;
	
	$blogid = intRequestVar('blogid');
	$log_text = trim(requestVar('logtext'));
	$log_link = requestVar('loglink');
	$log_linktitle = requestVar('loglinktitle');
	
	if (!BLOG::existsID($blogid))
		bm_doError(_ERROR_NOSUCHBLOG);

	if (!$member->isTeamMember($blogid))
		bm_doError(_ERROR_NOTONTEAM);
	
	$logje = '';
	if ($log_text)
		$logje .= '<blockquote><div>"' . htmlspecialchars($log_text) .'"</div></blockquote>' . "\n";
	if (!$log_linktitle)
		$log_linktitle = $log_link;
	if ($log_link) 
		$logje .= '<a href="'. htmlspecialchars($log_link) . '">'. htmlspecialchars($log_linktitle).'</a>';
		

	$item['body'] = $logje;
	$item['title'] = htmlspecialchars($log_linktitle);

	$factory = new PAGEFACTORY($blogid);
	$factory->createAddForm('bookmarklet',$item);
}

function bm_doEditForm() {
	global $member, $manager;
	
	$itemid = intRequestVar('itemid');
	
	if (!$manager->existsItem($itemid, 0, 0)) 
		bm_doError(_ERROR_NOSUCHITEM);
		
	if (!$member->canAlterItem($itemid))
		bm_doError(_ERROR_DISALLOWED);
		
	$item =& $manager->getItem($itemid,1,1);
	$blog =& $manager->getBlog(getBlogIDFromItemID($itemid));
	
	$manager->notify('PrepareItemForEdit', array('item' => &$item));

	if ($blog->convertBreaks()) {
		$item['body'] = removeBreaks($item['body']);
		$item['more'] = removeBreaks($item['more']);
	}

	$formfactory = new PAGEFACTORY($blog->getID());
	$formfactory->createEditForm('bookmarklet',$item);		

}

function bm_doError($msg) {
	bm_message(_ERROR,_ERRORMSG,$msg);
	die;
}

function bm_message($title, $head, $msg, $extrahead = '') {
	?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
	<html>
	<head>
		<title><?= $title ?></title>
		<? bm_style(); ?>
		<?=$extrahead?>
	</head>
	<body>
	<h1><?= $head ?></h1>
	<p><?= $msg ?></p>
	<p><a href="bookmarklet.php" onclick="window.close();"><?=_POPUP_CLOSE?></a></p>
	</body>
	</html>
	
	<?
}

function bm_style() {
	echo '<link rel="stylesheet" type="text/css" href="styles/bookmarklet.css" />';
	echo '<link rel="stylesheet" type="text/css" href="styles/addedit.css" />';	
}

?>