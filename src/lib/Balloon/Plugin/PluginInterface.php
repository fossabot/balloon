<?php
declare(strict_types=1);

/**
 * Balloon
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2012-2017 gyselroth GmbH (https://gyselroth.com)
 * @license     GPLv3 https://opensource.org/licenses/GPL-3.0
 */

namespace Balloon\Plugin;

use Balloon\Exception;
use \Psr\Log\LoggerInterface as Logger;
use Balloon\Filesystem;
use Balloon\Filesystem\Node\Collection;
use Balloon\Filesystem\Node\File;
use Balloon\Filesystem\Node\INode;
use Balloon\Auth\Adapter\AdapterInterface as AuthInterface;

interface PluginInterface
{
    /**
     * Create plugin
     *
     * @param  Iterable $config
     * @return void
     */
    public function __construct(Iterable $config, Logger $logger);


    /**
     * Set options
     *
     * @param  Iterable $config
     * @return PluginInterface
     */
    public function setOptions(?Iterable $config): PluginInterface;
   
 
    /**
     * Get plugin name
     *
     * @return string
     */
    public function getName(): string;

    
    /**
     * Check if plugin method does exists
     *
     * @param   string $method
     * @param   array $params
     * @return  void
     */
    public function __call(string $method, array $params=[]): void;


    /**
     * cli
     *
     * @param  Filesystem $fs
     * @return void
     */
    public function cli(Filesystem $fs): void;
    

    /**
     * Run: preAuthentication
     *
     * Executed pre authentication starts
     *
     * @param   array $adapters
     * @return  void
     */
    public function preAuthentication(array &$adapters): void;
    
    
    /**
     * Run: validAuthentication
     *
     * Executed post a valid authentication agains an adapter
     *
     * @param   string $identity
     * @param   object \Balloon\Auth\Adapter\IAdapter
     * @return  void
     */
    public function validAuthentication(string $identity, AuthInterface $adapter): void;
    
    
    /**
     * Run: invalidAuthentication
     *
     * Executed post no valid authentication was detected and pre program exit
     *
     * @param   arary $adapters
     * @return  void
     */
    public function invalidAuthentication(array $adapters): void;
    
    
    /**
     * Run: preHttpResponse
     *
     * Executed pre a http response will be send
     *
     * @param   \Balloon\Http\Response $response,
     * @param   int $code
     * @param   mixed $content
     * @return  void
     */
    public function preHttpResponse(\Balloon\Http\Response $response, int &$code, &$content): void;
    
    
    /**
     * Run: postHttpResponse
     *
     * Executed post a http response has been sent
     *
     * @param   \Balloon\Http\Response $response,
     * @param   int $code
     * @param   mixed $content
     * @return  void
     */
    public function postHttpResponse(\Balloon\Http\Response $response, int $code, $content): void;


    /**
     * Run: preRestoreFile
     *
     * Executed pre version rollback
     *
     * @param   File $node
     * @param   int $version
     * @return  void
     */
    public function preRestoreFile(File $node, int $version): void;


    /**
     * Run: postRestoreFile
     *
     * Executed post version rollback
     *
     * @param   File $node
     * @param   int $version
     * @return  void
     */
    public function postRestoreFile(File $node, int $version): void;


    /**
     * Run: prePutFile
     *
     * Executed pre a put file request
     *
     * @param   File $node
     * @param   string|resource $content
     * @param   bool $force
     * @param   array $attributes
     * @return  void
     */
    public function prePutFile(File $node, $content, bool $force, array $attributes): void;


    /**
     * Run: postPutFile
     *
     * Executed post a put file request
     *
     * @param   File $node
     * @param   string|resource $content
     * @param   bool $force
     * @param   array $attributes
     * @return  void
     */
    public function postPutFile(File $node, $content, bool $force, array $attributes): void;


    /**
     * Run: preCreateCollection
     *
     * Executed pre a directory will be created
     *
     * @param   Collection $parent
     * @param   string $name
     * @param   array $attributes
     * @param   bool $clone
     * @return  void
     */
    public function preCreateCollection(Collection $parent, string &$name, array &$attributes, bool $clone): void;
    

    /**
     * Run: postCreateCollection
     *
     * Executed post a directory was created
     *
     * @param   Collection $parent
     * @param   Collection $node
     * @param   bool $clone
     * @return  void
     */
    public function postCreateCollection(Collection $parent, Collection $node, bool $clone): void;


    /**
     * Run: preCreateFile
     *
     * Executed pre a create will be created
     *
     * @param   Collection $parent
     * @param   string $name
     * @param   array $attributes
     * @param   bool $clone
     * @return  void
     */
    public function preCreateFile(Collection $parent, string &$name, array &$attributes, bool $clone): void;


    /**
     * Run: postCreateFile
     *
     * Executed post a file was created
     *
     * @param   Collection $parent
     * @param   File $node
     * @param   bool $clone
     * @return  void
     */
    public function postCreateFile(Collection $parent, File $node, bool $clone): void;
    

    /**
     * Run: preCopyCollection
     *
     * Executed pre a directory will be cloned
     *
     * @param   Collection $node
     * @param   Collection $parent
     * @param   int $conflict
     * @param   string $recursion
     * @param   bool $recursion_first
     * @return  void
     */
    public function preCopyCollection(Collection $node, Collection $parent,
        int $conflict, ?string $recursion, bool $recursion_first): void;


    /**
     * Run: postCopyCollection
     *
     * Executed post a directory will be cloned
     *
     * @param   Collection $node
     * @param   Collection $parent
     * @param   Collection $new_node
     * @param   int $conflict
     * @param   string $recursion
     * @param   bool $recursion_first
     * @return  void
     */
    public function postCopyCollection(Collection $node, Collection $parent,
        Collection $new_node, int $conflict, ?string $recursion, bool $recursion_first): void;


    /**
     * Run: preCopyFile
     *
     * Executed pre a file will be cloned
     *
     * @param   File $node
     * @param   Collection $parent
     * @param   int $conflict
     * @param   string $recursion
     * @param   bool $recursion_first
     * @return  void
     */
    public function preCopyFile(File $node, Collection $parent,
       int $conflict, ?string $recursion, bool $recursion_first): void;


    /**
     * Run: postCopyFile
     *
     * Executed post a file will be cloned
     *
     * @param   File $node
     * @param   Collection $parent
     * @param   File $new_node
     * @param   int $conflict
     * @param   string $recursion
     * @param   bool $recursion_first
     * @return  void
     */
    public function postCopyFile(File $node, Collection $parent, File $new_node,
       int $conflict, ?string $recursion, bool $recursion_first): void;


    /**
     * Run: preDeleteCollection
     *
     * Executed pre a directory will be deleted
     *
     * @param   Collection $node
     * @param   bool $force
     * @param   string $recursion
     * @param   bool $recursion_first
     * @return  void
     */
    public function preDeleteCollection(Collection $node, bool $force, ?string $recursion, bool $recursion_first): void;


    /**
     * Run: postDeleteCollection
     *
     * Executed post a directory was deleted
     *
     * @param   Collection $node
     * @param   bool $force
     * @param   string $recursion
     * @param   bool $recursion_first
     * @return  void
     */
    public function postDeleteCollection(Collection $node, bool $force, ?string $recursion, bool $recursion_first): void;

    
    /**
     * Run: preDeleteFile
     *
     * Executed pre a file will be deleted
     *
     * @param   File $node
     * @param   bool $force
     * @param   string $recursion
     * @param   bool $recursion_first
     * @return  void
     */
    public function preDeleteFile(File $node, bool $force, ?string $recursion, bool $recursion_first): void;


    /**
     * Run: postDeleteFile
     *
     * Executed post a file was deleted
     *
     * @param   File $node
     * @param   bool $force
     * @param   string $recursion
     * @param   bool $recursion_first
     * @return  void
     */
    public function postDeleteFile(File $node, bool $force, ?string $recursion, bool $recursion_first): void;


    /**
     * Run: preSaveNodeAttributes
     *
     * Executed pre node attributes will be saved to mongodb
     *
     * @param   INode $node
     * @param   array $save_attributes
     * @param   array $remove_attributes
     * @param   string $recursion
     * @param   bool $recursion_first
     * @return  void
     */
    public function preSaveNodeAttributes(INode $node, array &$save_attributes,
        array &$remove_attributes, ?string $recursion, bool $recursion_first): void;

    
    /**
     * Run: postSaveNodeAttributes
     *
     * Executed post node attributes were saved to mongodb
     *
     * @param  INode $node
     * @param  array $save_attributes
     * @param  array $remove_attributes
     * @param  string $recursion
     * @param  bool $recursion_first
     * @return void
     */
    public function postSaveNodeAttributes(INode $node, array $save_attributes,
        array $remove_attributes, ?string $recursion, bool $recursion_first): void;
   
 
    /**
     * Run: preInstanceUser
     *
     * Executed pre user initialization
     *
     * @param   \Balloon\User $user
     * @param   string $username
     * @param   array $attributes
     * @param   bool $autocreate
     * @return  void
     */
    public function preInstanceUser(\Balloon\User $user, string &$username, ?array &$attributes, bool $autocreate): void;
    

    /**
     * Run: postInstanceUser
     *
     * Executed at the end of \Balloon\User::__construct()
     *
     * @param   \Balloon\User $user
     * @return  void
     */
    public function postInstanceUser(\Balloon\User $user): void;
}
