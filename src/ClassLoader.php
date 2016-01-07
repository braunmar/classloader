<?php

/*
 * (c) Marek Braun (braunmar)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace braunmar\simple\classloader; 

/**
 * Class robot loader.
 * 
 * See https://github.com/braunmar/classloader.git
 * 
 *      // example of basic usage
 *      braunmar\simple\classloader\ClassLoader::getInstance()
 *          ->addDir(__DIR__ . '/app')
 *          ->addDir(__DIR__ . '/libs')
 *          ->ignoreDir('trash')
 *          ->register();
 * 
 * This class can be used with cache class
 * See https://github.com/braunmar/simplecache
 */
class ClassLoader
{    
    /**
     * All accept extension of file
     * @var array
     */
    protected $acceptFile = ["php"];

    /**
     * Actual loaded class
     * @var $array
     */
    protected $classes = [];
    
    /**
     * Ignored dirs
     * @var array
     */
    protected $ignoreDirs = [];
    
    /**
     * Dirs where loader try find classes
     * @var type 
     */
    protected $dirs = [];
    
    /**
     * Instance of cache (must implement method cache and load)
     * @var type 
     */
    private $cache = false;
        
    /**
     * Static instance
     * @var ClassLoader
     */
    public static $instance;
    
    /**
     * Static constructor
     * @return ClassLoader
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new static;
        }
        
        return self::$instance;
    }
    
    /**
     * Register (enable) class loader
     * @param boolean $prepend sql_autoalod_register prepend parameter
     */
    public function register($prepend = false) 
    {       
        spl_autoload_register([$this, 'tryLoad'], true, $prepend);
    }
    
    /**
     * Unregister (disable) class loader
     */
    public function unregister()
    {
        spl_autoload_unregister([$this, 'tryLoad']);
    }
    
    /**
     * Add dir where class loader try find classes (works recursively)
     * @param string $dir Full dir path
     * @return ClassLoader $this
     */
    public function addDir($dir)
    {
        $this->dirs[] = $dir;
        
        return $this;
    }
    
    /**
     * Add dirs where class loader try find classes (works recursively)
     * @param array $dirs Array of ull dirpath where class loader try find classes
     * @return ClassLoader $this
     */
    public function addDirs(array $dirs)
    {
        $this->dirs = array_merge($this->dirs, $dirs);
        
        return $this;
    }
    
    /**
     * Add ignore dir. Dir is ignored when class loader try find classes
     * @param string $dir Full dir path
     * @return ClassLoader $this
     */
    public function addIgnoreDir($dir)
    {
        $this->ignoreDirs[] = $dir;
        
        return $this;
    }
    
    /**
     * Add ignore dirs. Dirs is ignored when class loader try find classes
     * @param array $dirs Array of full dir path
     * @return ClassLoader $this
     */
    public function addIgnoreDirs(array $dirs)
    {
        $this->ignoreDirs = array_merge($this->ignoreDirs, $dirs);
        
        return $this;
    }

    /**
     * Set cache class for store finded class. Class must implement methods "load()" and "cache($data)"
     * @param mixed $cache Instance of cache class
     * @param boolean $load If true, autoloader class from cache 
     * @return ClassLoader $this
     * @throws \InvalidArgumentException Cache must by a object and must implements methods "load()" and "cache($data)"
     */
    public function setCache($cache, $load = true)
    {
        if (!is_object($cache)) {
            throw new \InvalidArgumentException("Cache must by a object");
        }
        
        /* You don't wanna use Interface for this */
        if (!method_exists($cache, 'load') || !method_exists($cache, 'cache')) {
            throw new \InvalidArgumentException('Cache object must implement methods "load()" and "cache($data)');
        }
        
        $this->cache = $cache;
        
        if ($load) {
            $loadClasses = $this->cache->load();
            
            if (is_array($loadClasses)) {
                $this->classes = array_merge($this->classes, $loadClasses);
            }
            
            else if ($loadClasses instanceof \stdClass) {
                $loadClasses = $this->classes = array_merge($this->classes, (array) $loadClasses);
            }
        }
        
        return $this;
    }
    
    /**
     * Try load class
     * @param sting $type Full classname
     */
    protected function tryLoad($type)
    {
       /* Robot load */
       if (($path = $this->findClass($type)) !== false) {
           
            requireFile($path);
           
            if ($this->cache) {
                $this->cache->cache($this->classes);
            }
       }
    }
    
    /**
     * Find class
     * @param string $class Classname
     * @return string|boolean If finded return full path to file. If not return false
     */
    protected function findClass($class)
    {
        $class = ltrim($class, '\\');
        
        if (key_exists($class, $this->classes)) {
            return $this->classes[$class];      
        }
        
        if(is_file($file = "\\" . $class .'.php')) {
            $this->classes[$class] = $file;
            return $file;
        }
        
        $split = explode("\\", $class);                
        foreach ($this->dirs as $dir) {
            if(($file = $this->searchFor(end($split), $dir, $class)) !== false) {
                return $file;
            }
        }
        
        return false;
    }
    
    /**
     * Find class in dirs
     * @param string $class Classname
     * @param string $in Actual dir path
     * @param string $type Full classname (with namespaces)
     * @return string|boolean False if not finded. Full path to file if finded
     */
    protected function searchFor($class, $in, $type) 
    {
        $handle = opendir($in);
        
        if ($handle == false) {
            closedir($handle);
            return false; 
        }
        
        while (false !== ($entry = readdir($handle))) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $in . '/' . $entry;
            if (is_dir($path) && !in_array($entry, $this->ignoreDirs)) {

                if (($p = $this->searchFor($class, $path, $type)) !== false) {
                    closedir($handle);
                    return $p;
                }
            }

            if (is_file($path) && $this->getFilename($entry) === $class) {
                $this->classes[$type] = $path;
                closedir($handle);
                return $path;
            }
        }
        
        closedir($handle);

        return false;
    }
    
    /**
     * Get filename and check filename extension
     * @param string $filename
     * @return string Filename (withnout extension)
     */
   protected function getFilename($filename)
    {
       $extension = pathinfo($filename, PATHINFO_EXTENSION);
       
       if (!$extension || !in_array($extension, $this->acceptFile)) {
           return null;
       }
       
       return pathinfo($filename, PATHINFO_FILENAME);
    }
}

/**
 * Scope isolated require
 *
 * Prevents access to $this/self from included files.
 */
function requireFile($file)
{
    require $file;
}