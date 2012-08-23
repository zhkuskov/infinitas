<?php
/**
 * Upload behavior
 *
 * Enables users to easily add file uploading and necessary validation rules
 *
 * PHP versions 4 and 5
 *
 * Copyright 2010, Jose Diaz-Gonzalez
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright 2010, Jose Diaz-Gonzalez
 * @package       upload
 * @subpackage    upload.models.behaviors
 * @link          http://github.com/josegonzalez/upload
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
App::uses('Folder', 'Utility');
class UploadBehavior extends ModelBehavior {

	public $defaults = array(
		'rootDir'			=> null,
		'pathMethod'		=> 'primaryKey',
		'path'				=> '{ROOT}webroot{DS}files{DS}{model}{DS}{field}{DS}',
		'fields'			=> array('dir' => 'dir', 'type' => 'type', 'size' => 'size'),
		'mimetypes'			=> array(),
		'extensions'		=> array(),
		'maxSize'			=> 2097152,
		'minSize'			=> 8,
		'maxHeight'			=> 0,
		'minHeight'			=> 0,
		'maxWidth'			=> 0,
		'minWidth'			=> 0,
		'thumbnails'		=> true,
		'thumbnailMethod'	=> 'imagick',
		'thumbnailName'		=> null,
		'thumbnailPath'		=> null,
		'thumbnailPrefixStyle'=> true,
		'thumbnailQuality'	=> 75,
		'thumbnailSizes'	=> array(),
		'thumbnailType'		=> false,
		'deleteOnUpdate'	=> false,
		'mediaThumbnailType'=> 'png',
		'saveDir'			=> true,
	);

	protected $_imageMimetypes = array(
		'image/bmp',
		'image/gif',
		'image/jpeg',
		'image/pjpeg',
		'image/png',
		'image/vnd.microsoft.icon',
		'image/x-icon',
	);

	protected $_mediaMimetypes = array(
		'application/pdf',
		'application/postscript',
	);

	protected $_pathMethods = array('flat', 'primaryKey', 'random');

	protected $_resizeMethods = array('imagick', 'php');

	private $__filesToRemove = array();

	protected $_removingOnly = array();

/**
 * Runtime configuration for this behavior
 *
 * @var array
 **/
	public $runtime;

/**
 * Initiate Upload behavior
 *
 * @param object $model instance of model
 * @param array $config array of configuration settings.
 * @return void
 * @access public
 */
	public function setup(&$Model, $config = array()) {
		if (isset($this->settings[$Model->alias])) return;
		$this->settings[$Model->alias] = array();

		foreach ($config as $field => $options) {
			if (is_int($field)) {
				$field = $options;
				$options = array();
			}

			$this->_setupField($Model, $field, $options);
			$this->_generateVirtualFields($Model, $field);
		}
	}

/**
 * @brief set up virtual fields for the model object for easy access to the image files
 *
 * @param Model $Model
 * @param string $field
 *
 * @return boolean
 */
	protected function _generateVirtualFields($Model, $field) {
		$virtualFieldTemplate = sprintf(
			'CASE WHEN %%s IS NULL OR %%s = "" THEN "%%s" ELSE ' .
			'CONCAT("%s", %%s, "/%%s", %%s) END',
			$this->_getUrlPath($Model, $field)
		);

		$Model->virtualFields[$field . '_full'] = sprintf(
			$virtualFieldTemplate,
			$Model->alias . '.' . $field,
			$Model->alias . '.' . $field,
			$this->_emptyFilePath(),
			$Model->alias . '.' . $Model->primaryKey,
			'',
			$Model->alias . '.' . $field
		);

		if(empty($this->settings[$Model->alias][$field]['thumbnailSizes'])) {
			return true;
		}

		foreach($this->settings[$Model->alias][$field]['thumbnailSizes'] as $name => $size) {
			$Model->virtualFields[$field .  '_' . $name] = sprintf(
				$virtualFieldTemplate,
				$Model->alias . '.' . $field,
				$Model->alias . '.' . $field,
				$this->_emptyFilePath(),
				$Model->alias . '.' . $Model->primaryKey,
				$name . '_',
				$Model->alias . '.' . $field
			);
		}
	}

/**
 * @brief get the url path to where images are stored.
 *
 * @param Model $Model
 * @param string $field
 *
 * @return string
 */
	protected function _getUrlPath($Model, $field) {
		return str_replace(APP . 'webroot', '', $this->settings[$Model->alias][$field]['path']);
	}

	protected function _emptyFilePath() {
		return '/filemanager/img/no-image.png';
	}

/**
 * Setup a particular upload field
 *
 * @param AppModel $model Model instance
 * @param string $field Name of field being modified
 * @param array $options array of configuration settings for a field
 * @return void
 * @author Jose Diaz-Gonzalez
 */
	public function _setupField(&$model, $field, $options) {
		$this->defaults['rootDir'] = ROOT . DS . APP_DIR . DS;
		if (!isset($this->settings[$model->alias][$field])) {
			$options = array_merge($this->defaults, (array) $options);

			// HACK: Remove me in next major version
			if (!empty($options['thumbsizes'])) {
				$options['thumbnailSizes'] = $options['thumbsizes'];
			}

			if (!empty($options['prefixStyle'])) {
				$options['thumbnailPrefixStyle'] = $options['prefixStyle'];
			}
			// ENDHACK

			$options['fields'] += $this->defaults['fields'];
			if ($options['rootDir'] === null) {
				$options['rootDir'] = $this->defaults['rootDir'];
			}

			if ($options['thumbnailName'] === null) {
				if ($options['thumbnailPrefixStyle']) {
					$options['thumbnailName'] = '{size}_{filename}';
				} else {
					$options['thumbnailName'] = '{filename}_{size}';
				}
			}

			if ($options['thumbnailPath'] === null) {
				$options['thumbnailPath'] = Folder::slashTerm($this->_path($model, $field, array(
					'isThumbnail' => true,
					'path' => $options['path'],
					'rootDir' => $options['rootDir']
				)));
			} else {
				$options['thumbnailPath'] = Folder::slashTerm($this->_path($model, $field, array(
					'isThumbnail' => true,
					'path' => $options['thumbnailPath'],
					'rootDir' => $options['rootDir']
				)));
			}

			$options['path'] = Folder::slashTerm($this->_path($model, $field, array(
				'isThumbnail' => false,
				'path' => $options['path'],
				'rootDir' => $options['rootDir']
			)));

			if (!in_array($options['thumbnailMethod'], $this->_resizeMethods)) {
				$options['thumbnailMethod'] = 'imagick';
			}
			if (!in_array($options['pathMethod'], $this->_pathMethods)) {
				$options['pathMethod'] = 'primaryKey';
			}
			$options['pathMethod'] = '_getPath' . Inflector::camelize($options['pathMethod']);
			$options['thumbnailMethod'] = '_resize' . Inflector::camelize($options['thumbnailMethod']);
			$this->settings[$model->alias][$field] = $options;
		}
	}

/**
 * Convenience method for configuring UploadBehavior settings
 *
 * @param AppModel $model Model instance
 * @param string $field Name of field being modified
 * @param mixed $one A string or an array of data.
 * @param mixed $two Value in case $one is a string (which then works as the key).
 *   Unused if $one is an associative array, otherwise serves as the values to $one's keys.
 * @return void
 */
	public function uploadSettings(&$model, $field, $one, $two = null) {
		if (empty($this->settings[$model->alias][$field])) {
			$this->_setupField($model, $field, array());
		}

		$data = array();

		if (is_array($one)) {
			if (is_array($two)) {
				$data = array_combine($one, $two);
			} else {
				$data = $one;
			}
		} else {
			$data = array($one => $two);
		}
		$this->settings[$model->alias][$field] = $data + $this->settings[$model->alias][$field];
	}

/**
 * Before save method. Called before all saves
 *
 * Handles setup of file uploads
 *
 * @param AppModel $model Model instance
 * @return boolean
 */
	public function beforeSave(&$model) {
		$this->_removingOnly = array();
		foreach ($this->settings[$model->alias] as $field => $options) {
			if (!isset($model->data[$model->alias][$field]) || !is_array($model->data[$model->alias][$field])) {
				continue;
			}

			$this->runtime[$model->alias][$field] = $model->data[$model->alias][$field];

			$removing = isset($model->data[$model->alias][$field]['remove']);
			if ($removing || ($this->settings[$model->alias][$field]['deleteOnUpdate']
			&& isset($model->data[$model->alias][$field]['name'])
			&& strlen($model->data[$model->alias][$field]['name']))) {
				// We're updating the file, remove old versions
				if (!empty($model->id)) {
					$data = $model->find('first', array(
						'conditions' => array("{$model->alias}.{$model->primaryKey}" => $model->id),
						'contain' => false,
						'recursive' => -1,
					));
					$this->_prepareFilesForDeletion($model, $field, $data, $options);
				}

				if ($removing) {
					$model->data[$model->alias] = array(
						$field => null,
						$options['fields']['type'] => null,
						$options['fields']['size'] => null,
						$options['fields']['dir'] => null,
					);

					$this->_removingOnly[$field] = true;
					continue;
				} else {
					$model->data[$model->alias][$field] = array(
						$field => null,
						$options['fields']['type'] => null,
						$options['fields']['size'] => null,
					);
				}
			} elseif (!isset($model->data[$model->alias][$field]['name'])
			|| !strlen($model->data[$model->alias][$field]['name'])) {
				// if field is empty, don't delete/nullify existing file
				unset($model->data[$model->alias][$field]);
				continue;
			}

			$model->data[$model->alias] = array_merge($model->data[$model->alias], array(
				$field => $this->runtime[$model->alias][$field]['name'],
				$options['fields']['type'] => $this->runtime[$model->alias][$field]['type'],
				$options['fields']['size'] => $this->runtime[$model->alias][$field]['size']
			));
		}
		return true;
	}

	public function afterSave(&$model, $created) {
		$temp = array($model->alias => array());
		foreach ($this->settings[$model->alias] as $field => $options) {
			if (!in_array($field, array_keys($model->data[$model->alias]))) continue;
			if (empty($this->runtime[$model->alias][$field])) continue;
		        if (isset($this->_removingOnly[$field])) continue;

			$tempPath = $this->_getPath($model, $field);

			$path = $this->settings[$model->alias][$field]['path'];
			$thumbnailPath = $this->settings[$model->alias][$field]['thumbnailPath'];

			if (!empty($tempPath)) {
				$path .= $tempPath . DS;
				$thumbnailPath .= $tempPath . DS;
			}
			$tmp = $this->runtime[$model->alias][$field]['tmp_name'];
			$filePath = $path . $model->data[$model->alias][$field];
			if (!$this->handleUploadedFile($model->alias, $field, $tmp, $filePath)) {
				$model->invalidate($field, 'moveUploadedFile');
			}

			$this->_createThumbnails($model, $field, $path, $thumbnailPath);
			if ($model->hasField($options['fields']['dir'])) {
				if ($created && $options['pathMethod'] == '_getPathFlat') {
				} else if ($options['saveDir']) {
					$temp[$model->alias][$options['fields']['dir']] = $tempPath;
				}
			}
		}

		if (!empty($temp[$model->alias])) {
			foreach($temp[$model->alias] as $field => $value) {
				$model->saveField($field, $value);
			}
		}

		if (empty($this->__filesToRemove[$model->alias])) return true;
		foreach ($this->__filesToRemove[$model->alias] as $file) {
			$result[] = $this->unlink($file);
		}

		return $result;
	}

	public function handleUploadedFile($modelAlias, $field, $tmp, $filePath) {
		return !is_uploaded_file($tmp) || !@move_uploaded_file($tmp, $filePath);
	}

	public function unlink($file) {
		return @unlink($file);
	}

	public function beforeDelete(&$model, $cascade) {
		$data = $model->find('first', array(
			'conditions' => array("{$model->alias}.{$model->primaryKey}" => $model->id),
			'contain' => false,
			'recursive' => -1,
		));

		foreach ($this->settings[$model->alias] as $field => $options) {
			$this->_prepareFilesForDeletion($model, $field, $data, $options);
		}
		return true;
	}

	public function afterDelete(&$model) {
		$result = array();
		if(empty($this->__filesToRemove[$model->alias])) {
			return array();
		}

		foreach ($this->__filesToRemove[$model->alias] as $file) {
			$result[] = $this->unlink($file);
		}
		return $result;
	}

/**
 * Verify that the uploaded file has been moved to the
 * destination successfully. This rule is special that it
 * is invalidated in afterSave(). Therefore it is possible
 * for save() to return true and this rule to fail.
 *
 * @param Object $model
 * @return boolean Always true
 * @access public
 */
	public function moveUploadedFile(&$model) {
		return true;
	}
/**
 * Check that the file does not exceed the max
 * file size specified by PHP
 *
 * @param Object $model
 * @param mixed $check Value to check
 * @return boolean Success
 * @access public
 */
	public function isUnderPhpSizeLimit(&$model, $check) {
		$field = array_pop(array_keys($check));

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		return $check[$field]['error'] !== UPLOAD_ERR_INI_SIZE;
	}

/**
 * Check that the file does not exceed the max
 * file size specified in the HTML Form
 *
 * @param Object $model
 * @param mixed $check Value to check
 * @return boolean Success
 * @access public
 */
	public function isUnderFormSizeLimit(&$model, $check) {
		$field = array_pop(array_keys($check));

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		return $check[$field]['error'] !== UPLOAD_ERR_FORM_SIZE;
	}

/**
 * Check that the file was completely uploaded
 *
 * @param Object $model
 * @param mixed $check Value to check
 * @return boolean Success
 * @access public
 */
	public function isCompletedUpload(&$model, $check) {
		$field = array_pop(array_keys($check));

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		return $check[$field]['error'] !== UPLOAD_ERR_PARTIAL;
	}

/**
 * Check that a file was uploaded
 *
 * @param Object $model
 * @param mixed $check Value to check
 * @return boolean Success
 * @access public
 */
	public function isFileUpload(&$model, $check) {
		$field = array_pop(array_keys($check));

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		return $check[$field]['error'] !== UPLOAD_ERR_NO_FILE;
	}

/**
 * Check that the PHP temporary directory is missing
 *
 * @param Object $model
 * @param mixed $check Value to check
 * @return boolean Success
 * @access public
 */
	public function tempDirExists(&$model, $check, $requireUpload = true) {
		$field = array_pop(array_keys($check));

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		return $check[$field]['error'] !== UPLOAD_ERR_NO_TMP_DIR;
	}

/**
 * Check that the file was successfully written to the server
 *
 * @param Object $model
 * @param mixed $check Value to check
 * @return boolean Success
 * @access public
 */
	public function isSuccessfulWrite(&$model, $check, $requireUpload = true) {
		$field = array_pop(array_keys($check));

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		return $check[$field]['error'] !== UPLOAD_ERR_CANT_WRITE;
	}

/**
 * Check that a PHP extension did not cause an error
 *
 * @param Object $model
 * @param mixed $check Value to check
 * @return boolean Success
 * @access public
 */
	public function noPhpExtensionErrors(&$model, $check, $requireUpload = true) {
		$field = array_pop(array_keys($check));

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		return $check[$field]['error'] !== UPLOAD_ERR_EXTENSION;
	}

/**
 * Check that the file is of a valid mimetype
 *
 * @param Object $model
 * @param mixed $check Value to check
 * @param array $mimetypes file mimetypes to allow
 * @return boolean Success
 * @access public
 */
	public function isValidMimeType(&$model, $check, $mimetypes = array(), $requireUpload = true) {
		$field = array_pop(array_keys($check));

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		// Non-file uploads also mean the mimetype is invalid
		if (!isset($check[$field]['type']) || !strlen($check[$field]['type'])) {
			return false;
		}

		// Sometimes the user passes in a string instead of an array
		if (is_string($mimetypes)) {
			$mimetypes = array($mimetypes);
		}

		foreach ($mimetypes as $key => $value) {
			if (!is_int($key)) {
				$mimetypes = $this->settings[$model->alias][$field]['mimetypes'];
				break;
			}
		}

		if (empty($mimetypes)) $mimetypes = $this->settings[$model->alias][$field]['mimetypes'];

		return in_array($check[$field]['type'], $mimetypes);
	}

/**
 * Check that the upload directory is writable
 *
 *
 * @param Object $model
 * @param mixed $check Value to check
 * @param string $path Full upload path
 * @return boolean Success
 * @access public
 */
	public function isWritable(&$model, $check, $requireUpload = true) {
		$field = array_pop(array_keys($check));

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		return is_writable($this->settings[$model->alias][$field]['path']);
	}

/**
 * Check that the upload directory exists
 *
 * @param Object $model
 * @param mixed $check Value to check
 * @param string $path Full upload path
 * @return boolean Success
 * @access public
 */
	public function isValidDir(&$model, $check, $requireUpload = true) {
		$field = array_pop(array_keys($check));

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		return is_dir($this->settings[$model->alias][$field]['path']);
	}

/**
 * Check that the file is below the maximum file upload size
 *
 * @param Object $model
 * @param mixed $check Value to check
 * @param int $size Maximum file size
 * @return boolean Success
 * @access public
 */
	public function isBelowMaxSize(&$model, $check, $size = null, $requireUpload = true) {
		$field = array_pop(array_keys($check));

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		// Non-file uploads also mean the size is too small
		if (!isset($check[$field]['size']) || !strlen($check[$field]['size'])) {
			return false;
		}

		if (!$size) $size = $this->settings[$model->alias][$field]['maxSize'];

		return $check[$field]['size'] <= $size;
	}

/**
 * Check that the file is above the minimum file upload size
 *
 * @param Object $model
 * @param mixed $check Value to check
 * @param int $size Minimum file size
 * @return boolean Success
 * @access public
 */
	public function isAboveMinSize(&$model, $check, $size = null, $requireUpload = true) {
		$field = array_pop(array_keys($check));

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		// Non-file uploads also mean the size is too small
		if (!isset($check[$field]['size']) || !strlen($check[$field]['size'])) {
			return false;
		}

		if (!$size) $size = $this->settings[$model->alias][$field]['minSize'];

		return $check[$field]['size'] >= $size;
	}

/**
 * Check that the file has a valid extension
 *
 * @param Object $model
 * @param mixed $check Value to check
 * @param array $extensions file extenstions to allow
 * @return boolean Success
 * @access public
 */
	public function isValidExtension(&$model, $check, $extensions = array(), $requireUpload = true) {
		$field = array_pop(array_keys($check));

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		// Non-file uploads also mean the extension is invalid
		if (!isset($check[$field]['name']) || !strlen($check[$field]['name'])) {
			return false;
		}

		// Sometimes the user passes in a string instead of an array
		if (is_string($extensions)) {
			$extensions = array($extensions);
		}

		// Sometimes a user does not specify any extensions in the validation rule
		foreach ($extensions as $key => $value) {
			if (!is_int($key)) {
				$extensions = $this->settings[$model->alias][$field]['extensions'];
				break;
			}
		}

		if (empty($extensions)) $extensions = $this->settings[$model->alias][$field]['extensions'];
		$pathInfo = $this->_pathinfo($check[$field]['name']);

		return in_array($pathInfo['extension'], $extensions);
	}

/**
 * Check that the file is above the minimum height requirement
 *
 * @param Object $model
 * @param mixed $check Value to check
 * @param int $height Height of Image
 * @return boolean Success
 * @access public
 */
	public function isAboveMinHeight(&$model, $check, $height = null, $requireUpload = true) {
		$field = array_pop(array_keys($check));

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		// Non-file uploads also mean the height is too big
		if (!isset($check[$field]['tmp_name']) || !strlen($check[$field]['tmp_name'])) {
			return false;
		}

		if (!$height) $height = $this->settings[$model->alias][$field]['minHeight'];

		list($imgWidth, $imgHeight) = getimagesize($check[$field]['tmp_name']);
		return $height > 0 && $imgHeight >= $height;
	}

/**
 * Check that the file is below the maximum height requirement
 *
 * @param Object $model
 * @param mixed $check Value to check
 * @param int $height Height of Image
 * @return boolean Success
 * @access public
 */
	public function isBelowMaxHeight(&$model, $check, $height = null, $requireUpload = true) {
		$field = array_pop(array_keys($check));

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		// Non-file uploads also mean the height is too big
		if (!isset($check[$field]['tmp_name']) || !strlen($check[$field]['tmp_name'])) {
			return false;
		}

		if (!$height) $height = $this->settings[$model->alias][$field]['maxHeight'];

		list($imgWidth, $imgHeight) = getimagesize($check[$field]['tmp_name']);
		return $height > 0 && $imgHeight <= $height;
	}

/**
 * Check that the file is above the minimum width requirement
 *
 * @param Object $model
 * @param mixed $check Value to check
 * @param int $width Width of Image
 * @return boolean Success
 * @access public
 */
	public function isAboveMinWidth(&$model, $check, $width = null, $requireUpload = true) {
		$field = array_pop(array_keys($check));

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		// Non-file uploads also mean the height is too big
		if (!isset($check[$field]['tmp_name']) || !strlen($check[$field]['tmp_name'])) {
			return false;
		}

		if (!$width) $width = $this->settings[$model->alias][$field]['minWidth'];

		list($imgWidth, $imgHeight) = getimagesize($check[$field]['tmp_name']);
		return $width > 0 && $imgWidth >= $width;
	}

/**
 * Check that the file is below the maximum width requirement
 *
 * @param Object $model
 * @param mixed $check Value to check
 * @param int $width Width of Image
 * @return boolean Success
 * @access public
 */
	public function isBelowMaxWidth(&$model, $check, $width = null, $requireUpload = true) {
		$field = array_pop(array_keys($check));

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		// Non-file uploads also mean the height is too big
		if (!isset($check[$field]['tmp_name']) || !strlen($check[$field]['tmp_name'])) {
			return false;
		}

		if (!$width) $width = $this->settings[$model->alias][$field]['maxWidth'];

		list($imgWidth, $imgHeight) = getimagesize($check[$field]['tmp_name']);
		return $width > 0 && $imgWidth <= $width;
	}

	public function _resizeImagick(&$model, $field, $path, $size, $geometry, $thumbnailPath) {
		$srcFile  = $path . $model->data[$model->alias][$field];
		$pathInfo = $this->_pathinfo($srcFile);
		$thumbnailType = $imageFormat = $this->settings[$model->alias][$field]['thumbnailType'];

		$isMedia = $this->_isMedia($model, $this->runtime[$model->alias][$field]['type']);
		$image    = new imagick();

		if ($isMedia) {
			$image->setResolution(300, 300);
			$srcFile = $srcFile.'[0]';
		}

		$image->readImage($srcFile);
		$height   = $image->getImageHeight();
		$width    = $image->getImageWidth();

		if (preg_match('/^\\[[\\d]+x[\\d]+\\]$/', $geometry)) {
			// resize with banding
			list($destW, $destH) = explode('x', substr($geometry, 1, strlen($geometry)-2));
			$image->thumbnailImage($destW, $destH);
		} elseif (preg_match('/^[\\d]+x[\\d]+$/', $geometry)) {
			// cropped resize (best fit)
			list($destW, $destH) = explode('x', $geometry);
			$image->cropThumbnailImage($destW, $destH);
		} elseif (preg_match('/^[\\d]+w$/', $geometry)) {
			// calculate heigh according to aspect ratio
			$image->thumbnailImage((int)$geometry-1, 0);
		} elseif (preg_match('/^[\\d]+h$/', $geometry)) {
			// calculate width according to aspect ratio
			$image->thumbnailImage(0, (int)$geometry-1);
		} elseif (preg_match('/^[\\d]+l$/', $geometry)) {
			// calculate shortest side according to aspect ratio
			$destW = 0;
			$destH = 0;
			$destW = ($width > $height) ? (int)$geometry-1 : 0;
			$destH = ($width > $height) ? 0 : (int)$geometry-1;

			$imagickVersion = phpversion('imagick');
			$image->thumbnailImage($destW, $destH, !($imagickVersion[0] == 3));
		}

		if ($isMedia) {
			$thumbnailType = $imageFormat = $this->settings[$model->alias][$field]['mediaThumbnailType'];
		}

		if (!$thumbnailType || !is_string($thumbnailType)) {
			try {
				$thumbnailType = $imageFormat = $image->getImageFormat();
				// Fix file casing
				while (true) {
					$ext = false;
					$pieces = explode('.', $srcFile);
					if (count($pieces) > 1) {
						$ext = end($pieces);
					}

					if (!$ext || !strlen($ext)) {
						break;
					}

					$low = array(
						'ext' => strtolower($ext),
						'thumbnailType' => strtolower($thumbnailType),
					);

					if ($low['ext'] == 'jpg' && $low['thumbnailType'] == 'jpeg') {
						$thumbnailType = $ext;
						break;
					}

					if ($low['ext'] == $low['thumbnailType']) {
						$thumbnailType = $ext;
					}

					break;
				}
			} catch (Exception $e) {$this->log($e->getMessage(), 'upload');
				$thumbnailType = $imageFormat = 'png';
			}
		}

		$fileName = str_replace(
			array('{size}', '{filename}'),
			array($size, $pathInfo['filename']),
			$this->settings[$model->alias][$field]['thumbnailName']
		);

		$destFile = "{$thumbnailPath}{$fileName}.{$thumbnailType}";

		$image->setImageCompressionQuality($this->settings[$model->alias][$field]['thumbnailQuality']);
		$image->setImageFormat($imageFormat);
		if (!$image->writeImage($destFile)) {
			return false;
		}

		$image->clear();
		$image->destroy();
		return true;
	}

	public function _resizePhp(&$model, $field, $path, $size, $geometry, $thumbnailPath) {
		$srcFile  = $path . $model->data[$model->alias][$field];
		$pathInfo = $this->_pathinfo($srcFile);
		$thumbnailType = $this->settings[$model->alias][$field]['thumbnailType'];

		if (!$thumbnailType || !is_string($thumbnailType)) {
			$thumbnailType = $pathInfo['extension'];
		}

		if (!$thumbnailType) {
			$thumbnailType = 'png';
		}

		$fileName = str_replace(
			array('{size}', '{filename}'),
			array($size, $pathInfo['filename']),
			$this->settings[$model->alias][$field]['thumbnailName']
		);

		$destFile = "{$thumbnailPath}{$fileName}.{$thumbnailType}";

		copy($srcFile, $destFile);
		$src = null;
		$createHandler = null;
		$outputHandler = null;
		switch (strtolower($pathInfo['extension'])) {
			case 'gif':
				$createHandler = 'imagecreatefromgif';
				break;
			case 'jpg':
			case 'jpeg':
				$createHandler = 'imagecreatefromjpeg';
				break;
			case 'png':
				$createHandler = 'imagecreatefrompng';
				break;
			default:
				return false;
		}

		switch (strtolower($thumbnailType)) {
			case 'gif':
				$outputHandler = 'imagegif';
				break;
			case 'jpg':
			case 'jpeg':
				$outputHandler = 'imagejpeg';
				break;
			case 'png':
				$outputHandler = 'imagepng';
				break;
			default:
				return false;
		}

		if ($src = $createHandler($destFile)) {
			$srcW = imagesx($src);
			$srcH = imagesy($src);

			// determine destination dimensions and resize mode from provided geometry
			if (preg_match('/^\\[[\\d]+x[\\d]+\\]$/', $geometry)) {
				// resize with banding
				list($destW, $destH) = explode('x', substr($geometry, 1, strlen($geometry)-2));
				$resizeMode = 'band';
			} elseif (preg_match('/^[\\d]+x[\\d]+$/', $geometry)) {
				// cropped resize (best fit)
				list($destW, $destH) = explode('x', $geometry);
				$resizeMode = 'best';
			} elseif (preg_match('/^[\\d]+w$/', $geometry)) {
				// calculate heigh according to aspect ratio
				$destW = (int)$geometry-1;
				$resizeMode = false;
			} elseif (preg_match('/^[\\d]+h$/', $geometry)) {
				// calculate width according to aspect ratio
				$destH = (int)$geometry-1;
				$resizeMode = false;
			} elseif (preg_match('/^[\\d]+l$/', $geometry)) {
				// calculate shortest side according to aspect ratio
				if ($srcW > $srcH) $destW = (int)$geometry-1;
				else $destH = (int)$geometry-1;
				$resizeMode = false;
			}
			if (!isset($destW)) $destW = ($destH/$srcH) * $srcW;
			if (!isset($destH)) $destH = ($destW/$srcW) * $srcH;

			// determine resize dimensions from appropriate resize mode and ratio
			if ($resizeMode == 'best') {
				// "best fit" mode
				if ($srcW > $srcH) {
					if ($srcH/$destH > $srcW/$destW) $ratio = $destW/$srcW;
					else $ratio = $destH/$srcH;
				} else {
					if ($srcH/$destH < $srcW/$destW) $ratio = $destH/$srcH;
					else $ratio = $destW/$srcW;
				}
				$resizeW = $srcW*$ratio;
				$resizeH = $srcH*$ratio;
			} else if ($resizeMode == 'band') {
				// "banding" mode
				if ($srcW > $srcH) $ratio = $destW/$srcW;
				else $ratio = $destH/$srcH;
				$resizeW = $srcW*$ratio;
				$resizeH = $srcH*$ratio;
			} else {
				// no resize ratio
				$resizeW = $destW;
				$resizeH = $destH;
			}

			$img = imagecreatetruecolor($destW, $destH);
			imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
			imagecopyresampled($img, $src, ($destW-$resizeW)/2, ($destH-$resizeH)/2, 0, 0, $resizeW, $resizeH, $srcW, $srcH);
			$outputHandler($img, $destFile);
			return true;
		}
		return false;
	}

	public function _getPath(&$model, $field) {
		$path = $this->settings[$model->alias][$field]['path'];
		$pathMethod = $this->settings[$model->alias][$field]['pathMethod'];

		if (method_exists($this, $pathMethod)) {
			return $this->$pathMethod($model, $field, $path);
		}

		return $this->_getPathPrimaryKey($model, $field, $path);
	}

	public function _getPathFlat(&$model, $field, $path) {
		$destDir = $path;
		$this->_mkPath($destDir);
		return '';
	}

	public function _getPathPrimaryKey(&$model, $field, $path) {
		$destDir = $path . $model->id . DIRECTORY_SEPARATOR;
		$this->_mkPath($destDir);
		return $model->id;
	}

	public function _getPathRandom(&$model, $field, $path) {
		$endPath = null;
		$decrement = 0;
		$string = crc32($field . time());

		for ($i = 0; $i < 3; $i++) {
			$decrement = $decrement - 2;
			$endPath .= sprintf("%02d" . DIRECTORY_SEPARATOR, substr('000000' . $string, $decrement, 2));
		}

		$destDir = $path . $endPath;
		$this->_mkPath($destDir);

		return substr($endPath, 0, -1);
	}

	public function _mkPath($destDir) {
		if (!file_exists($destDir)) {
			@mkdir($destDir, 0777, true);
			@chmod($destDir, 0777);
		}
		return true;
	}

/**
 * Returns a path based on settings configuration
 *
 * @return void
 **/
	public function _path(&$model, $fieldName, $options = array()) {
		$defaults = array(
			'isThumbnail' => true,
			'path' => '{ROOT}webroot{DS}files{DS}{model}{DS}{field}{DS}',
			'rootDir' => $this->defaults['rootDir'],
		);

		$options = array_merge($defaults, $options);

		foreach ($options as $key => $value) {
			if ($value === null) {
				$options[$key] = $defaults[$key];
			}
		}

		if (!$options['isThumbnail']) {
			$options['path'] = str_replace(array('{size}', '{geometry}'), '', $options['path']);
		}

		$replacements = array(
			'{ROOT}'	=> $options['rootDir'],
			'{model}'	=> Inflector::underscore($model->alias),
			'{field}'	=> $fieldName,
			'{DS}'		=> DIRECTORY_SEPARATOR,
			'//'		=> DIRECTORY_SEPARATOR,
			'/'			=> DIRECTORY_SEPARATOR,
			'\\'		=> DIRECTORY_SEPARATOR,
		);

		$newPath = Folder::slashTerm(str_replace(
			array_keys($replacements),
			array_values($replacements),
			$options['path']
		));

		if ($newPath[0] !== DIRECTORY_SEPARATOR) {
			$newPath = $options['rootDir'] . $newPath;
		}

		$pastPath = $newPath;
		while (true) {
			$pastPath = $newPath;
			$newPath = str_replace(array(
				'//',
				'\\',
				DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR
			), DIRECTORY_SEPARATOR, $newPath);
			if ($pastPath == $newPath) {
				break;
			}
		}

		return $newPath;
	}

	public function _pathThumbnail(&$model, $field, $params = array()) {
		return str_replace(
			array('{size}', '{geometry}'),
			array($params['size'], $params['geometry']),
			$params['thumbnailPath']
		);
	}

	public function _createThumbnails(&$model, $field, $path, $thumbnailPath) {
		$isImage = $this->_isImage($model, $this->runtime[$model->alias][$field]['type']);
		$isMedia = $this->_isMedia($model, $this->runtime[$model->alias][$field]['type']);
		$createThumbnails = $this->settings[$model->alias][$field]['thumbnails'];
		$hasThumbnails = !empty($this->settings[$model->alias][$field]['thumbnailSizes']);

		if (($isImage || $isMedia) && $createThumbnails && $hasThumbnails) {
			$method = $this->settings[$model->alias][$field]['thumbnailMethod'];

			foreach ($this->settings[$model->alias][$field]['thumbnailSizes'] as $size => $geometry) {
				$thumbnailPathSized = $this->_pathThumbnail($model, $field, compact(
					'geometry', 'size', 'thumbnailPath'
				));
				$this->_mkPath($thumbnailPathSized);
				if (!$this->$method($model, $field, $path, $size, $geometry, $thumbnailPathSized)) {
					$model->invalidate($field, 'resizeFail');
				}
			}
		}
	}

	public function _isImage(&$model, $mimetype) {
		return in_array($mimetype, $this->_imageMimetypes);
	}

	public function _isMedia(&$model, $mimetype) {
		return in_array($mimetype, $this->_mediaMimetypes);
	}

	public function _getMimeType($filePath) {
		if (class_exists('finfo')) {
			$finfo = new finfo(FILEINFO_MIME_TYPE);
			return $finfo->file($filePath);
		}

		return mime_content_type($filePath);
	}

	public function _prepareFilesForDeletion(&$model, $field, $data, $options) {
		if (!strlen($data[$model->alias][$field])) return $this->__filesToRemove;

		$dir = $data[$model->alias][$options['fields']['dir']];
		$filePathDir = $this->settings[$model->alias][$field]['path'] . $dir . DS;
		$filePath = $filePathDir.$data[$model->alias][$field];
		$pathInfo = $this->_pathinfo($filePath);

		$this->__filesToRemove[$model->alias] = array();
		$this->__filesToRemove[$model->alias][] = $filePath;

		$createThumbnails = $options['thumbnails'];
		$hasThumbnails = !empty($options['thumbnailSizes']);

		if (!$createThumbnails || !$hasThumbnails) {
			return $this->__filesToRemove;
		}

		$DS = DIRECTORY_SEPARATOR;
		$mimeType = $this->_getMimeType($filePath);
		$isMedia = $this->_isMedia($model, $mimeType);
		$isImagickResize = $options['thumbnailMethod'] == 'imagick';
		$thumbnailType = $options['thumbnailType'];

		if ($isImagickResize) {
			if ($isMedia) {
				$thumbnailType = $options['mediaThumbnailType'];
			}

			if (!$thumbnailType || !is_string($thumbnailType)) {
				try {
					$srcFile = $filePath;
					$image    = new imagick();
					if ($isMedia) {
						$image->setResolution(300, 300);
						$srcFile = $srcFile.'[0]';
					}

					$image->readImage($srcFile);
					$thumbnailType = $image->getImageFormat();
				} catch (Exception $e) {
					$thumbnailType = 'png';
				}
			}
		} else {
			if (!$thumbnailType || !is_string($thumbnailType)) {
				$thumbnailType = $pathInfo['extension'];
			}

			if (!$thumbnailType) {
				$thumbnailType = 'png';
			}
		}

		foreach ($options['thumbnailSizes'] as $size => $geometry) {
			$fileName = str_replace(
				array('{size}', '{filename}'),
				array($size, $pathInfo['filename']),
				$options['thumbnailName']
			);

			$thumbnailPath = $options['thumbnailPath'];
			$thumbnailPath = $this->_pathThumbnail($model, $field, compact(
				'geometry', 'size', 'thumbnailPath'
			));

			$thumbnailFilePath = "{$thumbnailPath}{$dir}{$DS}{$fileName}.{$thumbnailType}";
			$this->__filesToRemove[$model->alias][] = $thumbnailFilePath;
		}
		return $this->__filesToRemove;
	}

	public function _pathinfo($filename) {
		$pathInfo = pathinfo($filename);

		if (!isset($pathInfo['extension']) || !strlen($pathInfo['extension'])) {
			$pathInfo['extension'] = '';
		}

		// PHP < 5.2.0 doesn't include 'filename' key in pathinfo. Let's try to fix this.
		if (empty($pathInfo['filename'])) {
			$pathInfo['filename'] = basename($pathInfo['basename'], '.' . $pathInfo['extension']);
		}
		return $pathInfo;
	}

	/**
	 * @brief get the upload path the model is set to use
	 *
	 * @access public
	 *
	 * @param object $Model the model being used
	 * @param string $field the field to check for
	 *
	 * @return mixed false if not found, string path if found
	 */
	public function uploadFilePath($Model, $field = '') {
		if(isset($this->settings[$Model->alias][$field]['path'])) {
			return str_replace('webroot/img/', '', $this->settings[$Model->alias][$field]['path']);
		}

		return false;
	}

	/**
	 * @brief get the full path for images
	 *
	 * @access public
	 *
	 * @param Model $Model the model the check is being done on
	 * @param string $field name to use
	 *
	 * @return type
	 */
	public function fullPath($Model, $field = '') {
		return APP . WEBROOT_DIR . DS . 'img' . DS . $this->uploadFilePath($Model, $field);
	}
}
