<?php
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
  *	This class contains two classes that can be used for importing and 
  *	exporting Nucleus skins: SKINIMPORT and SKINEXPORT
  */

class SKINIMPORT {
	
	/**
	 * constructor initializes data structures
	 */
	function SKINIMPORT() {
		// disable magic_quotes_runtime if it's turned on
		set_magic_quotes_runtime(0);
	
		// debugging mode?
		$this->debug = 0;
	
		$this->reset();
		
	}
	
	function reset() {
    	if ($this->parser)
    		xml_parser_free($this->parser);
    		
		// XML file pointer
		$this->fp = 0;		
		
		// which data has been read?
		$this->metaDataRead = 0;
		$this->allRead = 0;

		// to maintain track of where we are inside the XML file
		$this->inXml = 0;
		$this->inData = 0;
		$this->inMeta = 0;
		$this->inSkin = 0;
		$this->inTemplate = 0;
		$this->currentName = '';
		$this->currentPartName = '';
		
		// character data pile
		$this->cdata = '';
		
		// list of skinnames and templatenames (will be array of array)
		$this->skins = array();
		$this->templates = array();
		
		// extra info included in the XML files (e.g. installation notes)
		$this->info = '';
		
		// init XML parser
		$this->parser = xml_parser_create();
		xml_set_object($this->parser, $this);
		xml_set_element_handler($this->parser, 'startElement', 'endElement');
		xml_set_character_data_handler($this->parser, 'characterData');
		xml_parser_set_option($this->parser, XML_OPTION_CASE_FOLDING, 0);

	}	
	
	/**
	 * Reads an XML file into memory
	 *
	 * @param $filename
	 *		Which file to read
	 * @param $metaOnly
	 *		Set to 1 when only the metadata needs to be read (optional, default 0)
	 */
	function readFile($filename, $metaOnly = 0) {
		// open file
		$this->fp = @fopen($filename, 'r');
		if (!$this->fp) return 'Failed to open file/URL';
		
		// here we go!
		$this->inXml = 1;
		
		// parse file contents
		while (($buffer = fread($this->fp, 4096)) && (!$metaOnly || ($metaOnly && !$this->metaDataRead))) {
			$err = xml_parse( $this->parser, $buffer, feof($this->fp) );
			if (!$err && $this->debug) 
				echo 'ERROR: ', xml_error_string(xml_get_error_code($this->parser)), '<br />';
		}
			
		// all done
		$this->inXml = 0;
		fclose($this->fp);
	}
	
	/**
	 * Returns the list of skin names
	 */
	function getSkinNames() {
		return array_keys($this->skins);
	}

	/**
	 * Returns the list of template names
	 */
	function getTemplateNames() {
		return array_keys($this->templates);
	}	
	
	/**
	 * Returns the extra information included in the XML file
	 */
	function getInfo() {
		return $this->info;
	}
	
	/**
	 * Writes the skins and templates to the database 
	 *
	 * @param $allowOverwrite
	 *		set to 1 when allowed to overwrite existing skins with the same name
	 *		(default = 0)
	 */
	function writeToDatabase($allowOverwrite = 0) {
		$existingSkins = $this->checkSkinNameClashes();
		$existingTemplates = $this->checkTemplateNameClashes();
		
		// if not allowed to overwrite, check if any nameclashes exists
		if (!$allowOverwrite) {
			if ((sizeof($existingSkins) > 0) || (sizeof($existingTemplates) > 0))
				return 'Name clashes detected, re-run with allowOverwrite = 1 to force overwrite';
		}
		
		foreach ($this->skins as $skinName => $data) {
			// 1. if exists: delete all part data, update desc data
			//    if not exists: create desc
			if (in_array($skinName, $existingSkins)) {
				$skinObj = SKIN::createFromName($skinName);
				
				// delete all parts of the skin
				$skinObj->deleteAllParts();
				
				// update general info
				$skinObj->updateGeneralInfo($skinName, $data['description'], $data['type'], $data['includeMode'], $data['includePrefix']);
			} else {
				$skinid = SKIN::createNew($skinName, $data['description'], $data['type'], $data['includeMode'], $data['includePrefix']);
				$skinObj = new SKIN($skinid);
			}
			
			// 2. add parts
			foreach ($data['parts'] as $partName => $partContent) {
				$skinObj->update($partName, $partContent);
			}
		}
		
		foreach ($this->templates as $templateName => $data) {
			// 1. if exists: delete all part data, update desc data
			//    if not exists: create desc
			if (in_array($templateName, $existingTemplates)) {
				$templateObj = TEMPLATE::createFromName($templateName);
				
				// delete all parts of the template
				$templateObj->deleteAllParts();
				
				// update general info
				$templateObj->updateGeneralInfo($templateName, $data['description']);
			} else {
				$templateid = TEMPLATE::createNew($templateName, $data['description']);
				$templateObj = new TEMPLATE($templateid);
			}
			
			// 2. add parts
			foreach ($data['parts'] as $partName => $partContent) {
				$templateObj->update($partName, $partContent);
			}			
		}
	
			
	}
	
	/**
	  * returns an array of all the skin nameclashes (empty array when no name clashes)
	  */
	function checkSkinNameClashes() {
		$clashes = array();
		
		foreach ($this->skins as $skinName => $data) {
			if (SKIN::exists($skinName))
				array_push($clashes, $skinName);
		}
		
		return $clashes;
	}
	
	/**
	  * returns an array of all the template nameclashes 
	  * (empty array when no name clashes)
	  */
	function checkTemplateNameClashes() {
		$clashes = array();
		
		foreach ($this->templates as $templateName => $data) {
			if (TEMPLATE::exists($templateName))
				array_push($clashes, $templateName);
		}
		
		return $clashes;
	}
		
	/**
	 * Called by XML parser for each new start element encountered
	 */
	function startElement($parser, $name, $attrs) {
		if ($this->debug) echo 'START: ', $name, '<br />';
		
		switch ($name) {
			case 'nucleusskin':
				$this->inData = 1;
				break;
			case 'meta':
				$this->inMeta = 1;
				break;
			case 'info':
				// no action needed
				break;
			case 'skin':
				if (!$this->inMeta) {
					$this->inSkin = 1;
					$this->currentName = $attrs['name'];
					$this->skins[$this->currentName]['type'] = $attrs['type'];
					$this->skins[$this->currentName]['includeMode'] = $attrs['includeMode'];
					$this->skins[$this->currentName]['includePrefix'] = $attrs['includePrefix'];					
					$this->skins[$this->currentName]['parts'] = array();										
				} else {
					$this->skins[$attrs['name']] = array();				
					$this->skins[$attrs['name']]['parts'] = array();									
				}
				break;
			case 'template':
				if (!$this->inMeta) {
					$this->inTemplate = 1;
					$this->currentName = $attrs['name'];
					$this->templates[$this->currentName]['parts'] = array();															
				} else {
					$this->templates[$attrs['name']] = array();				
					$this->templates[$attrs['name']]['parts'] = array();									
				}
				break;
			case 'description':
				// no action needed
				break;
			case 'part':
				$this->currentPartName = $attrs['name'];
				break;
			default:
				echo 'UNEXPECTED TAG: ' , $name , '<br />';
				break;
		}
		
		// character data never contains other tags
		$this->clearCharacterData(); 
		
	}

	/**
	  * Called by the XML parser for each closing tag encountered
	  */
	function endElement($parser, $name) {
		if ($this->debug) echo 'END: ', $name, '<br />';
		
		switch ($name) {
			case 'nucleusskin':
				$this->inData = 0;
				$this->allRead = 1;
				break;
			case 'meta':
				$this->inMeta = 0;
				$this->metaDataRead = 1;
				break;
			case 'info':
				$this->info = $this->getCharacterData();
			case 'skin':
				if (!$this->inMeta) $this->inSkin = 0;
				break;
			case 'template':
				if (!$this->inMeta) $this->inTemplate = 0;			
				break;
			case 'description':
				if ($this->inSkin) {
					$this->skins[$this->currentName]['description'] = $this->getCharacterData();
				} else {
					$this->templates[$this->currentName]['description'] = $this->getCharacterData();				
				}
				break;
			case 'part':
				if ($this->inSkin) {
					$this->skins[$this->currentName]['parts'][$this->currentPartName] = $this->getCharacterData();
				} else {
					$this->templates[$this->currentName]['parts'][$this->currentPartName] = $this->getCharacterData();				
				}
				break;
			default:
				echo 'UNEXPECTED TAG: ' , $name, '<br />';
				break;
		}
		$this->clearCharacterData();

	}
	
	/**
	 * Called by XML parser for data inside elements
	 */
	function characterData ($parser, $data) {
		if ($this->debug) echo 'NEW DATA: ', htmlspecialchars($data), '<br />';
		$this->cdata .= $data;
	}
	
	/**
	 * Returns the data collected so far
	 */
	function getCharacterData() {
		return $this->cdata;
	}
	
	/**
	 * Clears the data buffer
	 */
	function clearCharacterData() {
		$this->cdata = '';
	}
	
	/**
	 * Static method that looks for importable XML files in subdirs of the given dir
	 */
	function searchForCandidates($dir) {
		$candidates = array();

		$dirhandle = opendir($dir);
		while ($filename = readdir($dirhandle)) {
			if (@is_dir($dir . $filename) && ($filename != '.') && ($filename != '..')) {
				$xml_file = $dir . $filename . '/skinbackup.xml';
				if (file_exists($xml_file) && is_readable($xml_file)) {
					$candidates[$filename] = $filename; //$xml_file;
				} 

				// backwards compatibility			
				$xml_file = $dir . $filename . '/skindata.xml';
				if (file_exists($xml_file) && is_readable($xml_file)) {
					$candidates[$filename] = $filename; //$xml_file;
				} 
			}
		}
		closedir($dirhandle);
		
		return $candidates;
	
	}
	 
	
}


class SKINEXPORT {
	
	/**
	 * Constructor initializes data structures
	 */
	function SKINEXPORT() {
		// list of templateIDs to export
		$this->templates = array();
		
		// list of skinIDs to export
		$this->skins = array();
		
		// extra info to be in XML file
		$this->info = '';
	}
	
	/**
	 * Adds a template to be exported
	 *
	 * @param id
	 *		template ID
	 * @result false when no such ID exists
	 */
	function addTemplate($id) {
		if (!TEMPLATE::existsID($id)) return 0;
	
		$this->templates[$id] = TEMPLATE::getNameFromId($id);
		
		return 1;
	}
	
	/**
	 * Adds a skin to be exported
	 *
	 * @param id 
	 *		skin ID
	 * @result false when no such ID exists
	 */	
	function addSkin($id) {
		if (!SKIN::existsID($id)) return 0;
		
		$this->skins[$id] = SKIN::getNameFromId($id);
		
		return 1;
	}
	
	/**
	 * Sets the extra info to be included in the exported file
	 */
	function setInfo($info) {
		$this->info = $info;
	}
	
	
	/**
	 * Outputs the XML contents of the export file
	 *
	 * @param $setHeaders
	 *		set to 0 if you don't want to send out headers
	 *		(optional, default 1)
	 */
	function export($setHeaders = 1) {
		if ($setHeaders) {
			// make sure the mimetype is correct, and that the data does not show up 
			// in the browser, but gets saved into and XML file (popup download window)
			header('Content-Type: text/xml');
			header('Content-Disposition: attachment; filename="skinbackup.xml"');
			header('Expires: 0');
			header('Pragma: no-cache');
		}
	
		echo '<nucleusskin>';
	
		// meta
		echo '<meta>';
			// skins
			foreach ($this->skins as $skinId => $skinName) {
				echo '<skin name="',htmlspecialchars($skinName),'" />';
			}
			// templates
			foreach ($this->templates as $templateId => $templateName) {
				echo '<template name="',htmlspecialchars($templateName),'" />';
			}
			// extra info
			if ($this->info)
				echo '<info><![CDATA[',$this->info,']]></info>';
		echo '</meta>';
		
		// contents skins
		foreach ($this->skins as $skinId => $skinName) {
			$skinObj = new SKIN($skinId);
			
			echo '<skin name="',htmlspecialchars($skinName),'" type="',htmlspecialchars($skinObj->getContentType()),'" includeMode="',htmlspecialchars($skinObj->getIncludeMode()),'" includePrefix="',htmlspecialchars($skinObj->getIncludePrefix()),'">';
			
			echo '<description>',htmlspecialchars($skinObj->getDescription()),'</description>';
			
			$res = sql_query('SELECT stype, scontent FROM '.sql_table('skin').' WHERE sdesc='.$skinId);
			while ($partObj = mysql_fetch_object($res)) {
				echo '<part name="',htmlspecialchars($partObj->stype),'"><![CDATA[',$partObj->scontent,']]></part>';
			}
			
			echo '</skin>';
		}
		
		// contents templates
		foreach ($this->templates as $templateId => $templateName) {
			
			echo '<template name="',htmlspecialchars($templateName),'">';
			
			echo '<description>',htmlspecialchars(TEMPLATE::getDesc($templateId)),'</description>';			
			
			$res = sql_query('SELECT tpartname, tcontent FROM '.sql_table('template').' WHERE tdesc='.$templateId);
			while ($partObj = mysql_fetch_object($res)) {
				echo '<part name="',htmlspecialchars($partObj->tpartname),'"><![CDATA[',$partObj->tcontent,']]></part>';
			}
			
			echo '</template>';
		}		
		
		echo '</nucleusskin>';
	}
}

?>