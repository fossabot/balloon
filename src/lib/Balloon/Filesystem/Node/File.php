<?php
declare(strict_types=1);

/**
 * Balloon
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2012-2017 gyselroth GmbH (https://gyselroth.com)
 * @license     GPLv3 https://opensource.org/licenses/GPL-3.0
 */

namespace Balloon\Filesystem\Node;

use \Sabre\DAV;
use Balloon\Exception;
use Balloon\Helper;
use Balloon\Queue;
use Balloon\User;
use \Psr\Log\LoggerInterface as Logger;
use Balloon\Plugin;
use Balloon\Filesystem;
use \MongoDB\BSON\ObjectId;
use \MongoDB\BSON\UTCDateTime;
use \MongoDB\Model\BSONDocument;

class File extends Node implements INode, DAV\IFile
{
    /**
     * History types
     */
    const HISTORY_CREATE    = 0;
    const HISTORY_EDIT      = 1;
    const HISTORY_RESTORE   = 2;
    const HISTORY_DELETE    = 3;
    const HISTORY_UNDELETE  = 4;


    /**
     * Empty content hash (NULL)
     */
    const EMPTY_CONTENT = 'd41d8cd98f00b204e9800998ecf8427e';


    /**
     * Temporary file patterns
     *
     * @param array
     **/
    protected $temp_files = [
        '/^\._(.*)$/',     // OS/X resource forks
        '/^.DS_Store$/',   // OS/X custom folder settings
        '/^desktop.ini$/', // Windows custom folder settings
        '/^Thumbs.db$/',   // Windows thumbnail cache
        '/^.(.*).swpx$/',  // ViM temporary files
        '/^.(.*).swx$/',   // ViM temporary files
        '/^.(.*).swp$/',   // ViM temporary files
        '/^\.dat(.*)$/',   // Smultron seems to create these
        '/^~lock.(.*)#$/', // Windows 7 lockfiles
    ];


    /**
     * MD5 Hash of the content
     *
     * @var string
     */
    protected $hash;
    

    /**
     * File version
     *
     * @var int
     */
    protected $version = 0;
    

    /**
     * File size
     *
     * @var int
     */
    protected $size = 0;


    /**
     * Mimetype
     *
     * @var string
     */
    protected $mime = '';


    /**
     * Thumbnail
     *
     * @var ObjectId
     */
    protected $thumbnail = false;


    /**
     * GridFS file id
     *
     * @var ObjectId
     */
    protected $file;

    
    /**
     * History
     *
     * @var array
     */
    protected $history = [];


    /**
     * Init virtual file and set attributes
     *
     * @param   BSONDocument $node
     * @param   Filesystem $fs
     * @param   bool $force
     * @return  void
     */
    public function __construct(BSONDocument $node, Filesystem $fs, bool $force=false)
    {
        parent::__construct($node, $fs);

        if($force === false) {
            $this->_verifyAccess();
        }
    }


    /**
     * Read content and return ressource
     *
     * @return resource
     */
    public function get()
    {
        try {
            if ($this->file === null) {
                return null;
            } else {
                return $this->_db->selectGridFSBucket()->openDownloadStream($this->file);
            }
        } catch (\Exception $e) {
            throw new Exception\NotFound('content not found',
                Exception\NotFound::CONTENT_NOT_FOUND
            );
        }
    }
  
 
    /**
     * Copy node
     *
     * @param  Collection $parent
     * @param  int $conflict
     * @param  string $recursion
     * @param  bool $recursion_first
     * @return INode
     */
    public function copyTo(Collection $parent, int $conflict=INode::CONFLICT_NOACTION, ?string $recursion=null, bool $recursion_first=true): INode
    {
        $this->_pluginmgr->run('preCopyFile',
            [$this, $parent, &$conflict, &$recursion, &$recursion_first]
        );

        if ($conflict === INode::CONFLICT_RENAME && $parent->childExists($this->name)) {
            $name = $this->_getDuplicateName();
        } else {
            $name = $this->name;
        }

        if ($conflict === INode::CONFLICT_MERGE && $parent->childExists($this->name)) {
            $result = $parent->getChild($this->name);
            $result->put($this->get());
        } else {
            $result = $parent->addFile($name, $this->get(), [
                'created' => $this->created,
                'changed' => $this->changed,
                'deleted' => $this->deleted,
                'thumbnail' => $this->thumbnail,
		'meta' => $this->meta
            ], INode::CONFLICT_NOACTION, true);
        }

        $this->_pluginmgr->run('postCopyFile',
            [$this, $parent, $result, $conflict, $recursion, $recursion_first]
        );

        return $result;
    }


    /**
     * Get history
     *
     * @return array
     */
    public function getHistory(): array
    {
        $history = $this->history;
        $filtered = [];
        
        foreach ($history as $version) {
            $v = (array)$version;

            $v['user'] = (new User($version['user'], $this->_logger, $this->_fs))
              ->getUsername();
            $v['changed'] = Helper::DateTimeToUnix($version['changed']);
            $filtered[] = $v;
        }

        return $filtered;
    }


    /**
     * Restore content to some older version
     *
     * @param   int $version
     * @return  bool
     */
    public function restore(int $version): bool
    {
        if (!$this->isAllowed('w')) {
            throw new Exception\Forbidden('not allowed to restore node '.$this->name,
                Exception\Forbidden::NOT_ALLOWED_TO_RESTORE
            );
        }

        $this->_pluginmgr->run('preRestoreFile', [$this, &$version]);
        
        if ($this->readonly) {
            throw new Exception\Conflict('node is marked as readonly, it is not possible to change any content',
                Exception\Conflict::READONLY
            );
        }

        if ($this->version == $version) {
            throw new Exception('file is already version '.$version);
        }

        $v = Helper::searchArray($version, 'version', $this->history);
        if ($v === null) {
            throw new Exception('failed restore file to version '.$version.', version was not found');
        }
    
        $file = $this->history[$v]['file'];
        if ($file !== null) {
            $exists = $this->_db->{'fs.files'}->findOne(['_id' => $file]);
            if ($exists === null) {
                throw new Exception('could not restore to version '.$v.', version content does not exists anymore');
            }
        }

        $current = $this->version;
        $new     = $this->increaseVersion();

        $this->history[] = [
            'version' => $new,
            'changed' => $this->changed,
            'user'    => $this->owner,
            'type'    => self::HISTORY_RESTORE,
            'origin'  => $this->history[$v]['version'],
            'file'    => $this->history[$v]['file'],
            'size'    => $this->history[$v]['size'],
            'mime'    => isset($this->history[$v]['mime']) ? $this->history[$v]['mime'] : null,
        ];
        
        try {
            $this->deleted = false;
            $this->version = $new;
            $this->file    = $this->history[$v]['file'];
            $this->hash    = $file === null ? self::EMPTY_CONTENT : $exists['md5'];
            $this->mime    = isset($this->history[$v]['mime']) ? $this->history[$v]['mime'] : null;
            $this->size    = $this->history[$v]['size'];
            $this->changed = $this->history[$v]['changed'];

            $this->save([
                'deleted',
                'version',
                'file',
                'hash',
                'mime',
                'size',
                'history',
                'changed'
            ]);

            $this->_pluginmgr->run('postRestoreFile', [$this, &$version]);
            
            $this->_logger->info('restored file ['.$this->_id.'] to version ['.$version.']', [
                'category' => get_class($this),
            ]);
        } catch (\Exception $e) {
            $this->_logger->error('failed restore file ['.$this->_id.'] to version ['.$version.']', [
                'category' => get_class($this),
                'exception' => $e,
            ]);

            throw $e;
        }

        return true;
    }


    /**
     * Delete node
     *
     * Actually the node will not be deleted (Just set a delete flag), set $force=true to
     * delete finally
     *
     * @param   bool $force
     * @param   string $recursion
     * @param   bool $recursion_first
     * @return  bool
     */
    public function delete(bool $force=false, ?string $recursion=null, bool $recursion_first=true): bool
    {
        if (!$this->isAllowed('w')) {
            throw new Exception\Forbidden('not allowed to delete node '.$this->name,
                Exception\Forbidden::NOT_ALLOWED_TO_DELETE
            );
        }

        $this->_pluginmgr->run('preDeleteFile', [$this, &$force, &$recursion, &$recursion_first]);

        if ($this->readonly && $this->_user !== null) {
            throw new Exception\Conflict('node is marked as readonly, it is not possible to delete it',
                Exception\Conflict::READONLY
            );
        }

        if ($force === true || $this->isTemporaryFile()) {
            $result = $this->_forceDelete();
            $this->_pluginmgr->run('postDeleteFile', [$this, $force, $recursion, $recursion_first]);
            return $result;
        }

        $ts = new UTCDateTime();
        $this->deleted  = $ts;
        $this->increaseVersion();
        
        $this->history[] = [
            'version' => $this->version,
            'changed' => $ts,
            'user'    => $this->_user->getId(),
            'type'    => self::HISTORY_DELETE,
            'file'    => $this->file,
            'size'    => $this->size,
        ];

        $result = $this->save([
            'version',
            'deleted',
            'history'
        ], [], $recursion, $recursion_first);

        $this->_pluginmgr->run('postDeleteFile', [$this, $force, $recursion, $recursion_first]);
        return $result;
    }


    /**
     * Check if file is temporary
     *
     * @return bool
     **/
    public function isTemporaryFile(): bool
    {
        foreach ($this->temp_files as $pattern) {
            if (preg_match($pattern, $this->name)) {
                return true;
            }
        }

        return false;
    }


    /**
     * Delete version
     *
     * @param  int $version
     * @return bool
     */
    public function deleteVersion(int $version): bool
    {
        $v = Helper::searchArray($version, 'version', $this->history);
        
        if ($v === null) {
            throw new Exception('version '.$version.' does not exists');
        }
        
        try {
            if ($this->history[$v]['file'] !== null) {
                $result = $this->_removeBlob($this->history[$v]['file']);
            }

            array_splice($this->history, $v, 1);
            
            $this->_logger->debug('removed version ['.$version.'] from file ['.$this->_id.']', [
                'category' => get_class($this),
            ]);

            return $this->save('history');
        } catch (\Exception $e) {
            $this->_logger->error('failed remove version ['.$version.'] from file ['.$this->_id.']', [
                'category' => get_class($this),
                'exception' => $e,
            ]);

            throw $e;
        }
    }


    /**
     * Remove blob from gridfs
     *
     * @param   ObjectId $file_id
     * @return  bool
     */
    protected function _removeBlob(ObjectId $file_id): bool
    {
        try {
            $bucket = $this->_db->selectGridFSBucket();
            $file   = $bucket->findOne(['_id' => $file_id]);

            if ($file) {
                if (!isset($file['metadata'])) {
                    $bucket->delete($file_id);
                    return true;
                }

                $ref  = $file['metadata']['ref'];
                
                $found = false;
                foreach ($ref as $key => $node) {
                    if ($node->id == $this->_id) {
                        unset($ref[$key]);
                    }
                }
                
                if (count($ref) >= 1) {
                    $this->_logger->debug('gridfs content node ['.$file['_id'].'] still has references left, just remove the reference ['.$this->_id.']', [
                        'category' => get_class($this),
                    ]);

                    $this->_db->{'fs.files'}->updateOne(['_id' => $file_id], [
                        '$set' => ['metadata.ref' => $ref]
                    ]);
                } else {
                    $this->_logger->debug('gridfs content node ['.$file['_id'].'] has no references left, delete node completley', [
                        'category' => get_class($this),
                    ]);

                    $bucket->delete($file_id);
                }
            } else {
                $this->_logger->debug('gridfs content node ['.$file_id.'] was not found, reference=['.$this->_id.']', [
                    'category' => get_class($this),
                ]);
            }

            return true;
        } catch (\Exception $e) {
            $this->_logger->error('failed remove gridfs content node ['.$file_id.']', [
                'category' => get_class($this),
                'exception' => $e,
            ]);

            throw $e;
        }
    }


    /**
     * Completly remove file
     *
     * @return bool
     */
    protected function _forceDelete(): bool
    {
        try {
            $this->deletePreview();
        } catch (\MongoDB\GridFS\Exception\FileNotFoundException $e) {
            $this->_logger->debug('could remove preview, preview ['.$this->thumbnail.'] does not exists', [
                'category' => get_class($this),
                'exception' => $e,
            ]);
        }

        try {
            $this->cleanHistory();
            $result = $this->_db->storage->deleteOne([
                '_id' => $this->_id,
            ]);
            
            $this->_logger->info('removed file node ['.$this->_id.']', [
                'category' => get_class($this),
            ]);
        } catch (\Exception $e) {
            $this->_logger->error('failed delete file node ['.$this->_id.']', [
                'category' => get_class($this),
                'exception' => $e,
            ]);

            throw $e;
        }

        return true;
    }


    /**
     * Cleanup history
     *
     * @return bool
     */
    public function cleanHistory(): bool
    {
        foreach ($this->history as $node) {
            $this->deleteVersion($node['version']);
        }

        return true;
    }


    /**
     * Delete preview
     *
     * @return bool
     */
    public function deletePreview(): bool
    {
        if (!$this->isAllowed('w')) {
            throw new Exception\Forbidden('not allowed to modify node',
                Exception\Forbidden::NOT_ALLOWED_TO_MODIFY
            );
        }

        $bucket = $this->_db->selectGridFSBucket(['bucketName' => 'thumbnail']);

        try {
            if ($this->thumbnail instanceof ObjectId) {
                $bucket->delete($this->thumbnail);
            
                $this->_logger->debug('removed preview ['.$this->thumbnail.'] from file ['.$this->_id.']', [
                    'category' => get_class($this),
                ]);
            
                return true;
            }
        } catch (\Exception $e) {
            $this->_logger->error('failed remove preview ['.$this->thumbnail.'] from file ['.$this->_id.']', [
                'category' => get_class($this),
                'exception' => $e,
            ]);

            throw $e;
        }

        return true;
    }


    /**
     * Get Attributes
     *
     * @param  string|array $attribute
     * @return array|string
     */
    public function getAttribute($attribute=[])
    {
        if (empty($attribute)) {
            $attribute = [
                'id',
                'name',
                'hash',
                'size',
                'version',
                'meta',
                'mime',
                'sharelink',
                'deleted',
                'changed',
                'created',
                'share',
                'directory'
            ];
        }
        
        $requested = (array)$attribute;
        $build = [];
        foreach ($requested as $key => $attr) {
            switch ($attr) {
               case 'hash':
               case 'version':
               case 'thumbnail':
                   $build[$attr] = $this->{$attr};
               break;
            
               case 'history':
                   $build['history'] = $this->getHistory();
               break;
            }
        }

        if (is_string($attribute) && !empty($build)) {
            return $build[$attribute];
        }

        $attributes = $this->_getAttribute($attribute);

        if (is_string($attribute)) {
            return $attributes;
        } else {
            return array_merge($build, $attributes);
        }
    }


    /**
     * Get preview
     *
     * @return string
     */
    public function getPreview(): string
    {
        if ($this->thumbnail instanceof ObjectId) {
            try {
                $stream = $this->_db->selectGridFSBucket(['bucketName' => 'thumbnail'])
                    ->openDownloadStream($this->thumbnail);
                $contents = stream_get_contents($stream);
                fclose($stream);

                return $contents;
            } catch (\Exception $e) {
                $this->_logger->warning('failed download preview from gridfs for file ['.$this->_id.']', [
                    'category'  => get_class($this),
                    'exception' => $e
                ]);
            }
        }

        throw new Exception\NotFound('preview does not exists',
            Exception\NotFound::PREVIEW_NOT_FOUND
        );
    }


    /**
     * Set thumbnail
     *
     * @param  resource $thumbnail
     * @return ObjectId
     */
    protected function storePreview(string $content): ObjectId
    {
        if (!$this->isAllowed('w')) {
            throw new Exception\Forbidden('not allowed to modify node',
                Exception\Forbidden::NOT_ALLOWED_TO_MODIFY
            );
        }

        try {
            $id     = new ObjectId();
            $bucket = $this->_db->selectGridFSBucket(['bucketName' => 'thumbnail']);
            $stream = $bucket->openUploadStream(null, ['_id' => $id]);
            fwrite($stream, $content);
            fclose($stream);
 
            $this->thumbnail = $id;
            $this->save('thumbnail');

            $this->_logger->info('stored new preview ['.$this->thumbnail.'] for file ['.$this->_id.']', [
                'category' => get_class($this),
            ]);
 
            return $id;
        } catch (\Exception $e) {
            $this->_logger->error('failed store preview for file ['.$this->_id.']', [
                'category' => get_class($this),
                'exception' => $e,
            ]);

            throw $e;
        }
    }


    /**
     * Rebuild thumbnail
     *
     * @return ObjectId
     */
    public function setPreview(string $content): ObjectId
    {
        $this->_logger->debug('set preview for file ['.$this->_id.']', [
            'category' => get_class($this),
        ]);
 
        try {
            $hash = md5($content);

            $found = $this->_db->{'thumbnail.files'}->findOne([
                'md5' => $hash,
            ], ['_id', 'thumbnail']);

            if ($found) {
                $this->_logger->debug('found preview ['.$found['_id'].'] with same hash, use stored preview', [
                    'category' => get_class($this),
                ]);

                $this->thumbnail = $found['_id'];
                $this->save('thumbnail');
                return $found['_id'];
            } else {
                return $this->storePreview($content);
            }
        } catch (\Exception $e) {
            $this->save([], 'thumbnail');
            throw $e;
        }
    }


    /**
     * Get filename extension
     *
     * @return string
     */
    public function getExtension(): string
    {
        $ext = strrchr($this->name, '.');
        if ($ext === false) {
            throw new Exception('file does not have an extension');
        } else {
            return substr($ext, 1);
        }
    }


    /**
     * Get mime type
     *
     * @return string
     */
    public function getContentType(): string
    {
        return $this->mime;
    }


    /**
     * Get file size
     *
     * @return int
     */
    public function getSize(): int
    {
        return $this->size;
    }


    /**
     * Get md5 sum of the file content,
     * actually the hash value comes from the database
     *
     * @return string
     */
    public function getETag(): string
    {
        return "'".$this->hash."'";
    }


    /**
     * Increase version
     *
     * @return int
     */
    protected function increaseVersion(): int
    {
        $this->version++;
        return $this->version;
    }


    /**
     * Write content to storage
     *
     * @param   resource $contents
     * @return  ObjectId
     */
    protected function _storeFile($contents): ObjectId
    {
        $file = [
            'ref' => [
                [
                    'id'    => $this->_id,
                    'owner' => $this->owner
                ]
            ],
        ];

        if ($this->isShareMember()) {
            $file['share_ref'] = [[
                'id'    => $this->_id,
                'share' => $this->shared
            ]];
        } else {
            $file['share_ref'] = [];
        }
 
        $bucket = $this->_db->selectGridFSBucket();
        $exists = $bucket->findOne(['md5' => $this->hash]);

        if ($exists) {
            $ref = $exists['metadata']['ref'];
            $set = [];

            $found = false;
            foreach ($ref as $node) {
                if ($node['id'] == (string)$this->_id) {
                    $found = true;
                }
            }

            if ($found === false) {
                $ref[] = [
                    'id'    => $this->_id,
                    'owner' => $this->owner
                ];
            }
            $set['metadata']['ref'] = $ref;

            $share_ref = $exists['metadata']['share_ref'];
            $found = false;
            foreach ((array)$share_ref as $node) {
                if ($node['id'] == (string)$this->_id) {
                    $found = true;
                }
            }

            if ($found === false && $this->isShareMember()) {
                $share_ref[] = [
                    'id'    => $this->_id,
                    'share' => $this->shared
                ];
            }

            $set['metadata']['share_ref'] = $share_ref;
            $this->_db->{'fs.files'}->updateOne(['md5' => $this->hash], [
                '$set' => $set
            ]);

            $this->_logger->info('gridfs content node with hash ['.$this->hash.'] does already exists, add new file reference for ['.$this->_id.']', [
                'category' => get_class($this),
            ]);

            return $exists['_id'];
        } else {
            set_time_limit((int)($this->size / 15339168));
            $id     = new \MongoDB\BSON\ObjectId();

            //somehow mongo-connector does not catch metadata when set during uploadFromStream()
            $stream = $bucket->uploadFromStream($id, $contents, ['_id' => $id/*, 'metadata' => $file*/]);
            $this->_db->{'fs.files'}->updateOne(['_id' => $id], [
              '$set' => ['metadata'=> $file]
            ]);
            
            $this->_logger->info('added new gridfs content node ['.$id.'] for file ['.$this->_id.']', [
                'category' => get_class($this),
            ]);
            
            return $id;
        }
    }


    /**
     * Create uuidv4
     *
     * @param  string $data
     * @return string
     */
    protected function guidv4(string $data): string
    {
        assert(strlen($data) == 16);

        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }


    /**
     * Unzip zip
     *
     * @return bool
     */
    public function unzip(): bool
    {
    }


    /**
     * Change content
     *
     * @param   resource|string $file
     * @param   bool $new
     * @param   array $attributes
     * @return  int
     */
    public function put($file, bool $new=false, array $attributes=[]): int
    {
        $this->_logger->debug('PUT new content data for ['.$this->_id.']', [
            'category' => get_class($this),
        ]);

        if (!$this->isAllowed('w')) {
            throw new Exception\Forbidden('not allowed to modify node',
                Exception\Forbidden::NOT_ALLOWED_TO_MODIFY
            );
        }

        $this->_pluginmgr->run('prePutFile', [$this, &$file, &$new, &$attributes]);

        if ($this->readonly) {
            throw new Exception\Conflict('node is marked as readonly, it is not possible to change any content',
                Exception\Conflict::READONLY
            );
        }

        if ($this->isShareMember() && $new === false && $this->getShareNode()->getAclPrivilege() === 'w') {
            throw new Exception\Forbidden('not allowed to overwrite node',
                Exception\Forbidden::NOT_ALLOWED_TO_OVERWRITE
            );
        }

        if (is_string($file)) {
            if (!is_readable($file)) {
                throw new Exception('file does not exists or is not readable');
            }

            $stream = fopen($file, 'r');
        } elseif (is_resource($file)) {
            $tmp = $this->_config->dir->temp.DIRECTORY_SEPARATOR.'upload'.DIRECTORY_SEPARATOR.$this->_user->getId();
            if (!file_exists($tmp)) {
                mkdir($tmp, 0700, true);
            }

            $tmp_file = $tmp.DIRECTORY_SEPARATOR.$this->guidv4(openssl_random_pseudo_bytes(16));
            $stream = fopen($tmp_file, 'w+');
            $size = stream_copy_to_stream($file, $stream, ((int)$this->_config->file->max_size + 1));
            rewind($stream);
            fclose($file);

            if ($size > (int)$this->_config->file->max_size) {
                unlink($tmp_file);
                throw new Exception\InsufficientStorage('file size exceeded limit',
                    Exception\InsufficientStorage::FILE_SIZE_LIMIT
                );
            }

            $file  = $tmp_file;
        }
        
       
        if ($file === null) {
            $size     = 0;
            $new_hash = self::EMPTY_CONTENT;
        } else {
            $size       = filesize($file);
            $this->size = $size;
            $new_hash   = md5_file($file);
        
            if (!$this->_user->checkQuota($size)) {
                $this->_logger->warning('could not execute PUT, user quota is full', [
                    'category' => get_class($this),
                ]);
            
                if ($new === true) {
                    $this->_forceDelete();
                }
            
                throw new Exception\InsufficientStorage('user quota is full',
                    Exception\InsufficientStorage::USER_QUOTA_FULL
                );
            }
        }
            
        if ($this->hash == $new_hash) {
            $this->_logger->info('stop PUT execution, put content and exists checksums are equal for file ['.$this->_id.']', [
                'category' => get_class($this),
            ]);

            //Remove tmp file
            if ($file !== null) {
                unlink($file);
                fclose($stream);
            }

            return $this->version;
        }
        
        $this->hash = $new_hash;
        $max = (int)(string)$this->_config->file->max_version;
        if (count($this->history) >= $max) {
            $del = key($this->history);
            $this->_logger->debug('history limit ['.$max.'] reached, remove oldest version ['.$del.'] from file ['.$this->_id.']', [
                'category' => get_class($this),
            ]);
            
            $this->deleteVersion($this->history[$del]['version']);
        }
        
        //Write new content
        if ($this->size > 0) {
            $file_id = $this->_storeFile($stream);
            $this->file = $file_id;
        } else {
            $this->file = null;
        }

        //Update current version
        $this->increaseVersion();
        
        //Get meta attributes
        if (isset($attributes['mime'])) {
            $this->mime = $attributes['mime'];
        } elseif ($file !== null) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $this->mime  = finfo_file($finfo, $file);
            if ($this->mime == 'application/zip' || $this->mime == 'application/vnd.ms-office') {
                $mime = $this->getMimeTypeFromExtension($this->name);
                if($mime !== null) {
                    $this->mime = $mime;
                }
            }
        }
       
        //Remove tmp file
        if ($file !== null) {
            unlink($file);
            fclose($stream);
        }

        $this->_logger->debug('set mime ['.$this->mime.'] for content, file=['.$this->_id.']', [
            'category' => get_class($this),
        ]);
 
        if ($this->version != 1) {
            if (isset($attributes['changed'])) {
                if (!($attributes['changed'] instanceof UTCDateTime)) {
                    throw new Exception\InvalidArgument('attribute changed must be an instance of UTCDateTime');
                }

                $this->changed = $attributes['changed'];
            } else {
                $this->changed = new UTCDateTime();
            }

            $this->_logger->debug('added new history version ['.$this->version.'] for file ['.$this->_id.']', [
                'category' => get_class($this),
            ]);

            $this->history[] = [
                'version' => $this->version,
                'changed' => $this->changed,
                'user'    => $this->_user->getId(),
                'type'    => self::HISTORY_EDIT,
                'file'    => $this->file,
                'size'    => $size,
                'mime'    => $this->mime,
            ];
        } else {
            $this->_logger->debug('added first file version [1] for file ['.$this->_id.']', [
                'category' => get_class($this),
            ]);

            $this->history[0] = [
                'version' => 1,
                'changed' => isset($attributes['changed']) ? $attributes['changed'] : new UTCDateTime(),
                'user'    => $this->owner,
                'type'    => self::HISTORY_CREATE,
                'file'    => $this->file,
                'size'    => $size,
                'mime'    => $this->mime,
            ];
        }

        //Update vfs
        try {
            $this->save([
                'size',
                'changed',
                'mime',
                'hash',
                'version',
                'history',
                'file'
            ]);
            
            $this->_logger->debug('modifed file metadata ['.$this->_id.']', [
                'category' => get_class($this),
            ]);
    
            $this->_pluginmgr->run('postPutFile', [$this, $file, $new, $attributes]);

            return $this->version;
        } catch (\Exception $e) {
            $this->_logger->error('failed modify file metadata ['.$this->_id.']', [
                'category' => get_class($this),
                'exception' => $e,
            ]);
    
            throw $e;
        }
    }


    /**
     * Get mimetype with string (file has not to be exists)
     *
     * @param  string $filename
     * @param  string $db mimetypes
     * @return string
     */
    public function getMimeTypeFromExtension(string $filename, string $db='/etc/mime.types'): ?string
    {
        if (!is_readable($db)) {
            throw new Exception('mime database '.$db.' was not found or is not readable');
        }

        $fileext = substr(strrchr($filename, '.'), 1);
        if (empty($fileext)) {
            return null;
        }
        $regex = "/^([\w\+\-\.\/]+)\s+(\w+\s)*($fileext\s)/i";
        $lines = file($db);

        foreach ($lines as $line) {
            if (substr($line, 0, 1) == '#') {
                continue;
            } // skip comments
            $line = rtrim($line) . " ";
            if (!preg_match($regex, $line, $matches)) {
                continue;
            } // no match to the extension
            return $matches[1];
        }
    
        return null; // no match at all
    }


    /**
     * Forbidden to load a child here
     *
     * @param   string $name
     * @return  void
     */
    public function getChild(string $name): void
    {
        throw new Exception('a file can not have children');
    }
}
