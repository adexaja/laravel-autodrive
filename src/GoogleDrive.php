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

    protected $dirInfo = "staff.stie.drive";

    public function __construct(Client $client){
        $this->client = $client;
        $this->service = $client->make("drive");
        $this->oAuth2 = $client->make("oauth2");
    }

    /**
     * @return \Google_Service_Oauth2_Userinfoplus
     */

    public function getUser(){
        if(!$this->client) {
            throw new \Google_Exception("You MUST initialize the Google_Client before attempting getUser()");
        }
        return $this->oAuth2->userinfo->get();
    }

    /**
     * @param string $allow
     * @return \Google_Service_Drive_Permission
     */
    public function getFilePermissions($allow="private") {
        $permission = new \Google_Service_Drive_Permission();
        switch($allow):
            case "private":
                $permission->setType('user');
                $permission->setRole('owner');
                break;

            default:
                $permission->setType('anyone');
                $permission->setRole('reader');
                break;
        endswitch;
        return $permission;
    }

    public function getSystemDirectoryInfo() {
        $dirinfo = Session::get($this->appName . "." . $this->dirInfo);
        return $dirinfo;
    }

    public function setSystemDirectoryInfo($sysdirinfo) {
        Session::put($this->appName . "." . $this->dirInfo, $sysdirinfo);
    }

    /**
     * @return \Google_Service_Drive_DriveFile
     */
    public function getSystemDirectory() {

            // there was a problem - re-make the system directory
            $params = array(
                'q'=>"mimeType = 'application/vnd.google-apps.folder' and name = '" . $this->dirInfo . "'",
                'pageSize'=>1
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
        // return the system directory
            return $sysdir;
    }

    /**
     * @param $name
     * @return \Google_Service_Drive_DriveFile
     */
    public function isDirectoryExists($name, $parentId = null) {
        // there was a problem - re-make the system directory

        if(!is_null($parentId)){
            $params = array(
                'q'=>"mimeType = 'application/vnd.google-apps.folder' and name = '" . $name . "' and '$parentId' in parents",
                'pageSize'=>1
            );
        }else{
            $params = array(
                'q'=>"mimeType = 'application/vnd.google-apps.folder' and name = '" . $name . "'",
                'pageSize'=>1
            );
        }

        $gquery = $this->service->files->listFiles($params);
        $sysdir = $gquery->getFiles();

        // sysdir not found
        if(empty($sysdir)):
            // create system directory
            $sysdir = $this->newDirectory($name, $parentId, "public");
            $this->setSystemDirectoryInfo($sysdir);
        else:
            $sysdir = $sysdir[0];
        endif;
        // return the system directory
        return $sysdir;
    }

    public function getFileUrl($fileId) {
        return "https://drive.google.com/open?id=" . $fileId;
    }

    /**
     * @param $path
     * @param $title
     * @param null $parentId
     * @param string $allow
     * @return \Google_Service_Drive_DriveFile
     */
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

    /**
     * @param $originFileId
     * @param $copyTitle
     * @param null $parentId
     * @param string $allow
     * @return \Google_Service_Drive_DriveFile
     */

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

    /**
     * @param $title
     * @param $description
     * @param $mimeType
     * @param $filename
     * @param null $parentId
     * @param string $allow
     * @return mixed
     * @throws \Google_Service_Drive_DriveFile
     */

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

    /**
     * @param $folderName
     * @param null $parentId
     * @param string $allow
     * @return \Google_Service_Drive_DriveFile
     */

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

    /**
     * @param null $pageToken
     * @param null $filters
     * @return array
     */

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
                        'pageSize'=>50
                    );
                else:
                    $parameters = array(
                        // 'q'=>"mimeType != 'application/vnd.google-apps.folder' and mimeType = 'image/gif' and mimeType = 'image/jpeg' and mimeType = 'image/png'",
                        'q'=>"mimeType != 'application/vnd.google-apps.folder'",
                        'pageSize'=>50
                    );
                endif;

                if($pageToken):
                    $parameters['pageToken'] = $pageToken;
                endif;

                $files = $this->service->files->listFiles($parameters);
                $result = $files->getFiles();
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
