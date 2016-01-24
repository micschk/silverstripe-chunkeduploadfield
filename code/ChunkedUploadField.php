<?php

/**
 * ChunkedUploadField uploads large files in chunks
 * 
 * Notes;
 * Chunked request extra headers:
 * X-File-Name:bu-20150204.zip
 * X-File-Size:49073575
 * X-File-Type:application/zip
 * X-Requested-With:XMLHttpRequest
 * 
 * Normal uploads:
 * Content-Disposition: form-data; 
 * name="Poster[Uploads][]"; 
 * filename="olaf-lawerman.jpg"
 * Content-Type: image/jpeg
 * 
 * Chunked:
 * Content-Disposition: form-data; 
 * name="MP4[Uploads][]"; 
 * filename="blob"
 * Content-Type: application/octet-stream
 * 
 * @author Michael van Schaik, based on Uploadfield by Zauberfisch
 * @package forms
 * @subpackages fields-files
 */
class ChunkedUploadField extends UploadField
{

    /**
     * @var array
     */
    private static $allowed_actions = array(
        'upload',
        'attach',
        'handleItem',
        'handleSelect',
        'fileexists'
    );

    /**
     * @var array
     */
    private static $url_handlers = array(
        'item/$ID' => 'handleItem',
        'select' => 'handleSelect',
        '$Action!' => '$Action',
    );

    /**
     * Construct a new ChunkedUploadField instance
     * 
     * @param string $name The internal field name, passed to forms.
     * @param string $title The field label.
     * @param SS_List $items If no items are defined, the field will try to auto-detect an existing relation on
     *                       @link $record}, with the same name as the field name.
     * @param Form $form Reference to the container form
     */
    public function __construct($name, $title = null, SS_List $items = null)
    {
        parent::__construct($name, $title);

        if ($items) {
            $this->setItems($items);
        }
        
        // set max chunk size
        $maxUpload = File::ini2bytes(ini_get('upload_max_filesize'));
        $maxPost = File::ini2bytes(ini_get('post_max_size'));
        $this->setConfig('maxChunkSize', round(min($maxUpload, $maxPost) *0.9)); // ~90%, allow some overhead
    }

    /**
     * Action to handle upload of a single file
     * 
     * @param SS_HTTPRequest $request
     * @return SS_HTTPResponse
     * @return SS_HTTPResponse
     */
    public function upload(SS_HTTPRequest $request)
    {
        if ($this->isDisabled() || $this->isReadonly() || !$this->canUpload()) {
            return $this->httpError(403);
        }

        // Protect against CSRF on destructive action
        $token = $this->getForm()->getSecurityToken();
        if (!$token->checkRequest($request)) {
            return $this->httpError(400);
        }
        
        // Get form details (name of the relation)
        $name = $this->getName();
        $postVars = $request->postVar($name);
        $uploadedFiles = $this->extractUploadedFileData($postVars);
        
        //
        // append all multiparts to one file here before proceeding
        //
        if ($request->getHeader('X-File-Name')) {
            // if chunked, get name from header
            //return Debug::dump($request->getHeader('X-File-Name'));
            $originalFileName = $request->getHeader('X-File-Name');
            $totalSize = $request->getHeader('X-File-Size');
            $uploadedChunkPath = $uploadedFiles[0]['tmp_name'];
            // We (mis)use the security ID as a way of 'unique-ifying' the temporary upload paths
            // so that we don't just depend on the original filename for this (or a scenario might
            // be possible to overwrite files based on an identical original name)
            // Added benefit it that the security ID will be different between form loads, which
            // makes the risk of appending to the same file over and over, a bit smaller
            $securityID = ($request->postVar('SecurityID')? $request->postVar('SecurityID'): 'none');
            // hash to prevent directory traversal etc posibilities based on original file name
            $temphash = sha1($securityID.$originalFileName);
            // eg /tmp/123somelonghash456 instead of $originalFileName.'.part'
            $tmpFilePath = dirname($uploadedChunkPath).DIRECTORY_SEPARATOR.$temphash;
            $append = file_exists($tmpFilePath);
            
            // If it is the first chunk we have to create the file, othewise we append...
            // Note file_put_contents with FILE_APPEND produces overlapping chunks for some reason...
            $out_fp = fopen($tmpFilePath, $append ? "ab" : "wb"); //append or write mode
            $in_fp = fopen($uploadedChunkPath, "rb");
            while ($buff = fread($in_fp, 4096)) {
                fwrite($out_fp, $buff);
            }
            fclose($out_fp);
            fclose($in_fp);
            
            // test if we're done with all chunks yet...
//			$done = (filesize($tmpFilePath)==$totalSize ? true : false);
            if (filesize($tmpFilePath) == $totalSize) {
                // move file to last uploaded chunks tmp_filename 
                // & set size etc for regular upload handling as if uploaded normally
                rename($tmpFilePath, $uploadedChunkPath);
                $uploadedFiles[0]['name'] = $originalFileName;
            } else {
                // not done yet, return for now...
                $return = array('ok' => '('.$uploadedChunkPath.' - '
                    .$tmpFilePath.': '.filesize($tmpFilePath).'/'.$totalSize.')');
                // Format response with json
                $response = new SS_HTTPResponse(Convert::raw2json(array($return)));
                $response->addHeader('Content-Type', 'text/plain');
                return $response;
            }
        } else {
            $originalFile = $request->requestVar('filename');
        }
        
        // Multipart done (or small enough to have been done in one chunk)...
        // Save the temporary file into a File object
        $firstFile = reset($uploadedFiles);
        $file = $this->saveTemporaryFile($firstFile, $error);

        if (empty($file)) {
            $return = array('error' => $error);
        } else {
            $return = $this->encodeFileAttributes($file);
        }
        
        // Format response with json
        $response = new SS_HTTPResponse(Convert::raw2json(array($return)));
        $response->addHeader('Content-Type', 'text/plain');
        if (!empty($return['error'])) {
            $response->setStatusCode(403);
        }
        return $response;
    }
}
