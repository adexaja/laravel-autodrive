<?php
/**
 * Created by PhpStorm.
 * User: rezki
 * Date: 07/01/17
 * Time: 10:49
 */

namespace PulkitJalan\Google;


use Exception;
use Illuminate\Support\Facades\Session;

class GoogleDrive
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var \Google_Service_Drive
     */
    protected $service;

    /**
     * @var \Google_Service_Oauth2
     */
    protected $oAuth2;

    protected $appName = "Google Drive Smart Kampus";

    protected $dirInfo = "adexaja.drive";

    public function _construct(Client $client){
        $this->client = $client;
        $this->service = $client->make("drive");
        $this->oAuth2 = $client->make("oauth2");
    }

    public function getUser(){
        if(!$this->client) {
            throw new \Google_Exception("You MUST initialize the Google_Client before attempting getUser()");
        }
        return $this->oAuth2->userinfo->get();
    }
    public function getFilePermissions($allow="private") {
        $permission = new \Google_Service_Drive_Permission();
        switch($allow):
            case "private":
                $permission->setRole('me');
                $permission->setType('default');
                $permission->setRole('owner');
                break;

            default:
                $permission->setRole('');
                $permission->setType('anyone');
                $permission->setRole('reader');
                break;
        endswitch;
        return $permission;
    }

    public function getSystemDirectoryInfo() {
        $dirinfo = Session::get($this->appName . "." . $this->dirInfo);
        return json_decode($dirinfo);
    }

    public function setSystemDirectoryInfo($sysdirinfo) {
        Session::put($this->appName . "." . $this->dirInfo, $sysdirinfo);
    }

    public function getSystemDirectory() {
        $dirinfo = $this->getSystemDirectoryInfo();
        if(!empty($dirinfo)):
            if(!empty($dirinfo->id)):
                $sysdir = $this->service->files->get($dirinfo->id);
            endif;
        else:
            // there was a problem - re-make the system directory
            $params = array(
                'q'=>"mimeType = 'application/vnd.google-apps.folder' and title = '" . self::SYSDIR . "'",
                'maxResults'=>1
            );
            $gquery = $this->service->files->listFiles($params);
            $sysdir = $gquery->getFiles();
            // sysdir not found
            if(empty($sysdir)):
                // create system directory
                $sysdir = $this->newDirectory($this->dirInfo, null, "public");
                $this->setSystemDirectoryInfo($sysdir);
            else:
                $sysdir = $sysdir[0];
            endif;
        endif;
        // return the system directory
        return $sysdir;
    }

    public function getFileUrl(\Google_Service_Drive_DriveFile $file, $parentId) {
        return "https://googledrive.com/host/{$parentId}/" . $file->getName();
    }

    public function uploadFile($path, $title, $parentId=null, $allow= "private") {
        /** @TODO Build in re-try parameters **/
        $newFile = new \Google_Service_Drive_DriveFile();
        if ($parentId != null) {
            $newFile->setParents(array($parentId));
        }
        $newFile->setName($title);
        $newFile->setDescription($this->appName . " file uploaded " . gmdate("jS F, Y H:i A") . " GMT");
        $newFile->setMimeType(mime_content_type($path));

        $permission = $this->getFilePermissions($allow);
        $remoteNewFile = $this->service->files->create($newFile, array(
            'data'=>file_get_contents($path),
            'mimeType'=>  mime_content_type($path)
        ));
        $fileId = $remoteNewFile->getId();
        if(!empty($fileId)):
            $this->service->permissions->create($fileId, $permission);
            return $remoteNewFile;
        endif;
    }

    public function copyFile($originFileId, $copyTitle, $parentId=null, $allow="private") {
        $copiedFile = new \Google_Service_Drive_DriveFile();
        $copiedFile->setName($copyTitle);
        try {
            // Set the parent folder.
            if ($parentId != null) {
                $copiedFile->setParents(array($parentId));
            }
            $newFile = $this->service->files->copy($originFileId, $copiedFile);
            $permission = $this->getFilePermissions($allow);
            $this->service->permissions->create($newFile->getId(), $permission);

            return $newFile;

        } catch (Exception $e) {
            print "An error occurred: " . $e->getMessage();
        }
        return NULL;
    }

    public function newFile($title, $description, $mimeType, $filename, $parentId=null, $allow="private") {

        $file = new \Google_Service_Drive_DriveFile();
        $file->setName($title);
        $file->setDescription($description);
        $file->setMimeType($mimeType);

        // Set the parent folder.
        if ($parentId != null) {
            $file->setParents(array($parentId));
        }

        try {
            $data = file_get_contents($filename);

            $createdFile = $this->service->files->create($file, array(
                'data' => $data,
                'mimeType' => $mimeType,
            ));

            $permission = new \Google_Service_Drive_Permission();
            switch($allow):
                case "private":
                    $permission->setRole('me');
                    $permission->setType('default');
                    $permission->setRole('owner');
                    break;

                default:
                    $permission->setRole('');
                    $permission->setType('anyone');
                    $permission->setRole('reader');
                    break;
            endswitch;

            $this->service->permissions->create($createdFile->getId(), $permission);

            // Uncomment the following line to print the File ID
            // print 'File ID: %s' % $createdFile->getId();

            return $createdFile;

        } catch (Exception $e) {
            throw new Exception("An error occurred: " . $e->getMessage());
        }
    }

    public function newDirectory($folderName, $parentId=null, $allow="private") {
        $file = new \Google_Service_Drive_DriveFile();
        $file->setName($folderName);
        $file->setMimeType('application/vnd.google-apps.folder');

        // Set the parent folder.
        if ($parentId != null) {
            $file->setParents(array($parentId));
        }

        $createdFile = $this->service->files->create($file, array(
            'mimeType'=>'application/vnd.google-apps.folder'
        ));

        $permission = new \Google_Service_Drive_Permission();
        switch($allow):
            case "private":
                $permission->setRole('me');
                $permission->setType('default');
                $permission->setRole('owner');
                break;

            default:
                $permission->setRole('');
                $permission->setType('anyone');
                $permission->setRole('reader');
                break;
        endswitch;

        $this->service->permissions->create($createdFile->getId(), $permission);

        return $createdFile;
    }

    public function getFiles($pageToken=null, $filters=null) {
        try {
            $result = array();
            $errors = array();

            try {

                if(!empty($filters)):
                    $where = "";

                    foreach($filters as $i=>$filter):
                        if($i>0):
                            $where .= " and {$filter}";
                        else:
                            $where .= $filter;
                        endif;
                    endforeach;

                    $parameters = array(
                        'q'=>$where,
                        'maxResults'=>50
                    );
                else:
                    $parameters = array(
                        // 'q'=>"mimeType != 'application/vnd.google-apps.folder' and mimeType = 'image/gif' and mimeType = 'image/jpeg' and mimeType = 'image/png'",
                        'q'=>"mimeType != 'application/vnd.google-apps.folder'",
                        'maxResults'=>50
                    );
                endif;

                if($pageToken):
                    $parameters['pageToken'] = $pageToken;
                endif;

                $files = $this->service->files->listFiles($parameters);
                $result = array_merge($result, $files->getFiles());
                $pageToken = $files->getNextPageToken();

            } catch (Exception $ex) {
                $pageToken = NULL;
                $errors[] = $ex->getMessage();
            }

            return array(
                'success'=>true,
                'files'=>$result,
                'nextPageToken'=>$pageToken,
                'errors'=>$errors,
                'parameters'=> $parameters
            );

        } catch (Exception $ex) {
            return array('success'=>false, 'message'=>$ex->getMessage());
        }
    }

}