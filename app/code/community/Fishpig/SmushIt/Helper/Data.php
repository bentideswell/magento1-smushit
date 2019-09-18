<?php
/**
 * @category    Fishpig
 * @package    Fishpig_SmushIt
 * @license      http://fishpig.co.uk/license.txt
 * @author       Ben Tideswell <ben@fishpig.co.uk>
 */
class Fishpig_SmushIt_Helper_Data extends Mage_Core_Helper_Abstract
{
	/**
	 * API end point URL
	 *
	 * @const string
	 */
	const API_URL = 'http://api.resmush.it/ws.php';

	/**
	 * Postfix of the meta file
	 *
	 * @const string
	 */
	const META_FILE_POSTFIX = '.meta';
	
	/**
	 * Smush all of the images
	 *
	 * @return $this
	 */
	public function run()
	{
		if (!$this->isEnabled()) {
			return $this->_log($this->__('The Smush.it extension is not enabled.'));
		}
		
		if (!$this->isValidLicenseCode()) {
			return $this->_log($this->__('Invalid license code.'));
		}
		
		Mage::getResourceSingleton('smushit/image')->installDatabaseTables();

		$source = $this->getSourceDirectory();
		
		if (!is_dir($source)) {
			@mkdir($source, 0777, true);
			
			if (!is_dir($source)) {
				return $this->_log($this->__('Your product image cache directory (%s) does not exist.', $source));
			}
		}

		$files = $this->scanDirectory($source, $this->getLimit());

		if (!$files) {
			return $this->_log($this->__('Your product image cache directory (%s) is empty or all images have been optimised.', $source));
		}

		foreach($files as $file) {
			try {
				$this->smushFile($file);
			}
			catch (Exception $e) {
				$this->_logForFile($file, $e->getMessage());
			}
		}
		
		return $this;
	}

	/**
	 * Optimise a file
	 * This will replace the existing file with the optimised file
	 *
	 * @param string $file
	 * @return $this
	 */
	public function smushFile($file)
	{
		if (!is_writable($file)) {
			throw new Exception($file . ' is not writable.');
		}
		
		if (!is_writable(dirname($file))) {
			throw new Exception(dirname($file) . ' is not writable.');
		}

		$postData = array('files' => $this->_curlFileCreate($file));
		
		if ($this->_isJpg($file) && ($quality = $this->_getJpgQuality())) {
			$postData['qlty'] = $quality;
		}

		$result = @json_decode($this->_curlRequest(self::API_URL, array(
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $postData,
		), $file), true);

		if (!$result) {
			throw new Exception('No response from the API');
		}

		$result = new Varien_Object((array)$result);
			
		if (!$result->getPercent()) {
			@file_put_contents($file . self::META_FILE_POSTFIX, json_encode($result->getData()));
			Mage::getResourceModel('smushit/image')->createUsingResult($file, $result->getData());

			return $this;
		}

		$newFile = $this->_curlRequest($result->getDest());
		
		if (!$newFile) {
			throw new Exception('Unable to download optimised file.');
		}

		@file_put_contents($file, $newFile);
		@file_put_contents($file . self::META_FILE_POSTFIX, json_encode($result->getData()));
		
		Mage::getResourceModel('smushit/image')->createUsingResult($file, $result->getData());

		$this->_logForFile($file, $result->getPercent() . '% savings.');

		return $this;
	}

	/**
	 * Wraper for curl_file_create
	 * Adds legacy support for older PHP versions
	 *
	 * @param string $file
	 * @return string
	 */
	protected function _curlFileCreate($file)
	{
		if (function_exists('curl_file_create')) {
			if (function_exists('exif_imagetype')) {
				return curl_file_create($file, exif_imagetype($file), basename($file));
			}
	
			$imageData = getimagesize($file);

			return curl_file_create($file, $imageData[2], basename($file));
		}

		if (function_exists('finfo_open')) {
			$finfo 		= finfo_open(FILEINFO_MIME);
			$mime 	= finfo_file($finfo, $file);
							   finfo_close($finfo);

			if (strpos($mime, ';') !== false) {
				$mime = substr($mime, 0, strpos($mime, ';'));
			}
		}

		return "@$file;filename=" . basename($file) . ";type=$mime";
	}	

	/**
	 * Make a request via CURL
	 *
	 * @param string $url
	 * @param array $params = array()
	 * @return mixed
	 */
	protected function _curlRequest($url, array $params = array(), $file = '')
	{
		$params += array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 1.1; rv:10.0) Gecko/20100101 Firefox/10.0',
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HEADER =>  false,
			CURLOPT_URL => $url,
			CURLOPT_SSL_VERIFYPEER => false,
		);

		$ch = curl_init();

		curl_setopt_array($ch, $params);
		
		$result = @curl_exec($ch);

		if ($curlErrNo = curl_errno($ch)) {
			$curlError = curl_error($ch);
			curl_close($ch);

			throw new Exception($this->__('CURL Error #%s: %s. File was %s.', $curlErrNo, $curlError, $file));
		}
				
		curl_close($ch);

		return $result;
	}
	
	/**
	 * Get the source directory for images
	 *
	 * @return string
	 */
	public function getSourceDirectory()
	{
		return Mage::getBaseDir() . DS . 'media' . DS . 'catalog' . DS . 'product' . DS . 'cache' . DS		;
	}

	/**
	 * Scan $dir and return all directories and files in an array
	 *
	 * @param string $dir
	 * @param bool $reverse = false
	 * @return array
	 */
	public function scanDirectory($dir, $limit = 0) {
		$files = array();
		
		foreach(scandir($dir) as $file) {
			if (trim($file, '.') === '') {
				continue;
			}
			
			if (strpos($file, self::META_FILE_POSTFIX) !== false) {
				continue;
			}

			$tmp = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file;

			if (!is_dir($tmp)) {
				if (!is_file($tmp . self::META_FILE_POSTFIX)) {
					$files[] = $tmp;
				}
			}
			else {
				$files = array_merge($files, $this->scanDirectory($tmp, $limit));
			}
			
			if ($limit > 0 && count($files) > $limit) {
				break;
			}
		}
		
		if ($limit > 0 && count($files) > $limit) {
			return array_slice($files, 0, $limit);
		}

		return $files;
	}
	
	/**
	 * Log a message for a file
	 *
	 * @param string $file
	 * @param string $msg
	 * @return $this
	 */
	protected function _logForFile($file, $msg)
	{
		return $this->_log(substr($file, strlen(Mage::getBaseDir('media')) + 1) . ' - ' . $msg);
	}
	
	/**
	 * Log a message for a file
	 *
	 * @param string $msg
	 * @return $this
	 */
	protected function _log($msg)
	{
		Mage::log(
			$msg,
			null,
			'smushit.log',
			true
		);
		
		return $this;
	}
	
	/**
	 * Get the image limit
	 *
	 * @return int
	 */
	public function getLimit()
	{
		return (int)Mage::getStoreConfig('smushit/settings/limit');
	}
	
	/**
	 * Get the image limit
	 *
	 * @return int
	 */
	public function isEnabled()
	{
		return Mage::getStoreConfigFlag('smushit/settings/enabled');
	}
	
	/**
	 * Validate the license code
	 *
	 * @return bool
	 */
	public function isValidLicenseCode()
	{
		Mage::helper('smushit/license')->validate();
		
		return true;
	}
	
	/**
	 * Determine whether the Magento CRON is running
	 *
	 * @return bool
	 */
	public function validateCronRunning()
	{
		$db = Mage::getSingleton('core/resource')->getConnection('core_read');
		$table = Mage::getSingleton('core/resource')->getTableName('cron/schedule');
		$date = date('Y-m-d H:i:s', strtotime('-4 hours'));
		
		$scheduleId = $db->fetchOne($db->select()
			->from($table, 'schedule_id')
			->where('job_code = ?', 'smushit_cron')
			->where('created_at > ?', $date)
			->limit(1));
		
		if ($scheduleId) {
			return $this;
		}
		
		$scheduleId = $db->fetchOne($db->select()
			->from($table, 'schedule_id')
			->where('created_at > ?', $date)
			->limit(1));
			
		if ($scheduleId) {
			throw new Exception('The Magento CRON appears to be setup but no CRON jobs for Smush.it have been created. Please try again in 5 minutes.');
		}
		
		throw new Exception('The Magento CRON is not setup. Please setup your Magento CRON for Smush.it to function correctly.');
	}

	/*
	 *
	 *
	 */
	protected function _isJpg($file)
	{
		$file = strtolower($file);

		return substr($file, -4) === '.jpg' || substr($file, -5) === '.jpeg';
	}
	
	/*
	 *
	 *
	 */
	protected function _getJpgQuality()
	{
		$quality = Mage::getStoreConfig('smushit/jpg/quality');
		
		if (empty($quality)) {
			return false;
		}

		return max(0, min((int)$quality, 100));
	}
}
