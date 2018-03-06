<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;


/**
 * Provide methods to handle file uploads in the back end.
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class FileUpload extends \Backend
{

	/**
	 * Error indicator
	 * @var boolean
	 */
	protected $blnHasError = false;

	/**
	 * Resized indicator
	 * @var boolean
	 */
	protected $blnHasResized = false;

	/**
	 * Field name
	 * @var string
	 */
	protected $strName = 'files';


	/**
	 * Make the constructor public
	 */
	public function __construct()
	{
		parent::__construct();
	}


	/**
	 * Return true if there was an error
	 *
	 * @return boolean
	 */
	public function hasError()
	{
		return $this->blnHasError;
	}


	/**
	 * Return true if there was a resized image
	 *
	 * @return boolean
	 */
	public function hasResized()
	{
		return $this->blnHasResized;
	}


	/**
	 * Override the field name
	 *
	 * @param string $strName
	 */
	public function setName($strName)
	{
		$this->strName = $strName;
	}


	/**
	 * Check the uploaded files and move them to the target directory
	 *
	 * @param string $strTarget
	 *
	 * @return array
	 *
	 * @throws \Exception
	 */
	public function uploadTo($strTarget)
	{
		if ($strTarget == '' || \Validator::isInsecurePath($strTarget))
		{
			throw new \InvalidArgumentException('Invalid target path ' . $strTarget);
		}

		$maxlength_kb = $this->getMaximumUploadSize();
		$maxlength_kb_readable = $this->getReadableSize($maxlength_kb);
		$arrUploaded = array();
		$arrFiles = $this->getFilesFromGlobal();

		foreach ($arrFiles as $file)
		{
			// Sanitize the filename
			try
			{
				$file['name'] = \StringUtil::sanitizeFileName($file['name']);
			}
			catch (\InvalidArgumentException $e)
			{
				\Message::addError($GLOBALS['TL_LANG']['ERR']['filename']);
				$this->blnHasError = true;

				continue;
			}

			// Invalid file name
			if (!\Validator::isValidFileName($file['name']))
			{
				\Message::addError($GLOBALS['TL_LANG']['ERR']['filename']);
				$this->blnHasError = true;
			}

			// File was not uploaded
			elseif (!is_uploaded_file($file['tmp_name']))
			{
				if ($file['error'] == 1 || $file['error'] == 2)
				{
					\Message::addError(sprintf($GLOBALS['TL_LANG']['ERR']['filesize'], $maxlength_kb_readable));
					$this->blnHasError = true;
				}
				elseif ($file['error'] == 3)
				{
					\Message::addError(sprintf($GLOBALS['TL_LANG']['ERR']['filepartial'], $file['name']));
					$this->blnHasError = true;
				}
				elseif ($file['error'] > 0)
				{
					\Message::addError(sprintf($GLOBALS['TL_LANG']['ERR']['fileerror'], $file['error'], $file['name']));
					$this->blnHasError = true;
				}
			}

			// File is too big
			elseif ($file['size'] > $maxlength_kb)
			{
				\Message::addError(sprintf($GLOBALS['TL_LANG']['ERR']['filesize'], $maxlength_kb_readable));
				$this->blnHasError = true;
			}

			// Move the file to its destination
			else
			{
				$strExtension = strtolower(substr($file['name'], strrpos($file['name'], '.') + 1));

				// File type not allowed
				if (!\in_array($strExtension, \StringUtil::trimsplit(',', strtolower(\Config::get('uploadTypes')))))
				{
					\Message::addError(sprintf($GLOBALS['TL_LANG']['ERR']['filetype'], $strExtension));
					$this->blnHasError = true;
				}
				else
				{
					$this->import('Files');
					$strNewFile = $strTarget . '/' . $file['name'];

					// Set CHMOD and resize if neccessary
					if ($this->Files->move_uploaded_file($file['tmp_name'], $strNewFile))
					{
						$this->Files->chmod($strNewFile, 0666 & ~umask());

						// Notify the user
						\Message::addConfirmation(sprintf($GLOBALS['TL_LANG']['MSC']['fileUploaded'], $file['name']));
						$this->log('File "' . $strNewFile . '" has been uploaded', __METHOD__, TL_FILES);

						// Resize the uploaded image if necessary
						$this->resizeUploadedImage($strNewFile);

						$arrUploaded[] = $strNewFile;
					}
				}
			}
		}

		return $arrUploaded;
	}


	/**
	 * Generate the markup for the default uploader
	 *
	 * @return string
	 */
	public function generateMarkup()
	{
		$return = '
  <div>
    <input type="file" name="' . $this->strName . '[]" class="tl_upload_field" onfocus="Backend.getScrollOffset()" multiple>
  </div>';

		if (isset($GLOBALS['TL_LANG']['tl_files']['fileupload'][1]))
		{
			$return .= '
  <p class="tl_help tl_tip">' . sprintf($GLOBALS['TL_LANG']['tl_files']['fileupload'][1], \System::getReadableSize($this->getMaximumUploadSize()), \Config::get('gdMaxImgWidth') . 'x' . \Config::get('gdMaxImgHeight')) . '</p>';
		}

		return $return;
	}


	/**
	 * Get the files from the global $_FILES array
	 *
	 * @return array
	 */
	protected function getFilesFromGlobal()
	{
		// The "multiple" attribute is not set
		if (!\is_array($_FILES[$this->strName]['name']))
		{
			return array($_FILES[$this->strName]);
		}

		$arrFiles = array();
		$intCount = \count($_FILES[$this->strName]['name']);

		for ($i=0; $i<$intCount; $i++)
		{
			if ($_FILES[$this->strName]['name'][$i] == '')
			{
				continue;
			}

			$arrFiles[] = array
			(
				'name'     => $_FILES[$this->strName]['name'][$i],
				'type'     => $_FILES[$this->strName]['type'][$i],
				'tmp_name' => $_FILES[$this->strName]['tmp_name'][$i],
				'error'    => $_FILES[$this->strName]['error'][$i],
				'size'     => $_FILES[$this->strName]['size'][$i],
			);
		}

		return $arrFiles;
	}


	/**
	 * Return the maximum upload file size in bytes
	 *
	 * @return string
	 */
	protected function getMaximumUploadSize()
	{
		// Get the upload_max_filesize from the php.ini
		$upload_max_filesize = ini_get('upload_max_filesize');

		// Convert the value to bytes
		if (stripos($upload_max_filesize, 'K') !== false)
		{
			$upload_max_filesize = round($upload_max_filesize * 1024);
		}
		elseif (stripos($upload_max_filesize, 'M') !== false)
		{
			$upload_max_filesize = round($upload_max_filesize * 1024 * 1024);
		}
		elseif (stripos($upload_max_filesize, 'G') !== false)
		{
			$upload_max_filesize = round($upload_max_filesize * 1024 * 1024 * 1024);
		}

		return min($upload_max_filesize, \Config::get('maxFileSize'));
	}


	/**
	 * Resize an uploaded image if neccessary
	 *
	 * @param string $strImage
	 *
	 * @return boolean
	 */
	protected function resizeUploadedImage($strImage)
	{
		// The feature is disabled
		if (\Config::get('imageWidth') < 1 && \Config::get('imageHeight') < 1)
		{
			return false;
		}

		$objFile = new \File($strImage);

		// Not an image
		if (!$objFile->isSvgImage && !$objFile->isGdImage)
		{
			return false;
		}

		$arrImageSize = $objFile->imageSize;

		// The image is too big to be handled by the GD library
		if ($objFile->isGdImage && ($arrImageSize[0] > \Config::get('gdMaxImgWidth') || $arrImageSize[1] > \Config::get('gdMaxImgHeight')))
		{
			\Message::addInfo(sprintf($GLOBALS['TL_LANG']['MSC']['fileExceeds'], $objFile->basename));
			$this->log('File "' . $strImage . '" is too big to be resized automatically', __METHOD__, TL_FILES);

			return false;
		}

		$blnResize = false;

		// The image exceeds the maximum image width
		if ($arrImageSize[0] > \Config::get('imageWidth'))
		{
			$blnResize = true;
			$intWidth = \Config::get('imageWidth');
			$intHeight = round(\Config::get('imageWidth') * $arrImageSize[1] / $arrImageSize[0]);
			$arrImageSize = array($intWidth, $intHeight);
		}

		// The image exceeds the maximum image height
		if ($arrImageSize[1] > \Config::get('imageHeight'))
		{
			$blnResize = true;
			$intWidth = round(\Config::get('imageHeight') * $arrImageSize[0] / $arrImageSize[1]);
			$intHeight = \Config::get('imageHeight');
			$arrImageSize = array($intWidth, $intHeight);
		}

		// Resized successfully
		if ($blnResize)
		{
			\System::getContainer()
				->get('contao.image.image_factory')
				->create(TL_ROOT . '/' . $strImage, array($arrImageSize[0], $arrImageSize[1]), TL_ROOT . '/' . $strImage)
			;

			\Message::addInfo(sprintf($GLOBALS['TL_LANG']['MSC']['fileResized'], $objFile->basename));
			$this->log('File "' . $strImage . '" was scaled down to the maximum dimensions', __METHOD__, TL_FILES);
			$this->blnHasResized = true;

			return true;
		}

		return false;
	}
}
