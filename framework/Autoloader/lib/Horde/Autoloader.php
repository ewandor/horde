<?php
/**
 * Horde_Autoloader
 *
 * Manages an application's class name to file name mapping conventions. One or
 * more class-to-filename mappers are defined, and are searched in LIFO order.
 *
 * @author   Bob Mckee <bmckee@bywires.com>
 * @author   Chuck Hagenbuch <chuck@horde.org>
 * @category Horde
 * @package  Autoloader
 */
class Horde_Autoloader
{
    private $_mappers = array();
    private $_callbacks = array();
    private $_cache;

    public function __construct()
    {
        if (extension_loaded('apc')) {
            $this->_cache = apc_fetch('horde_autoloader_map');
        } elseif (extension_loaded('xcache')) {
            $this->_cache = xcache_get('horde_autoloader_map');
        } elseif (extension_loaded('eaccelerator')) {
            $this->_cache = eaccelerator_get('horde_autoloader_map');
        }
    }

    public function __destruct()
    {
        if (extension_loaded('apc')) {
            apc_store('horde_autoloader_map', $this->_cache);
        } elseif (extension_loaded('xcache')) {
            xcache_set('horde_autoloader_map', $this->_cache);
        } elseif (extension_loaded('eaccelerator')) {
            eaccelerator_put('horde_autoloader_map', $this->_cache);
        }
    }

    public function loadClass($className)
    {
        if ($path = $this->mapToPath($className)) {
            if ($this->_include($path)) {
                $className = strtolower($className);
                if (isset($this->_callbacks[$className])) {
                    call_user_func($this->_callbacks[$className]);
                }
                return true;
            }
        }

        return false;
    }

    public function addClassPathMapper(Horde_Autoloader_ClassPathMapper $mapper)
    {
        array_unshift($this->_mappers, $mapper);
        return $this;
    }

    /**
     * Add a callback to run when a class is loaded through loadClass().
     *
     * @param string $class    The classname.
     * @param mixed $callback  The callback to run when the class is loaded.
     */
    public function addCallback($class, $callback)
    {
        $this->_callbacks[strtolower($class)] = $callback;
    }

    public function registerAutoloader()
    {
        // Register the autoloader in a way to play well with as many
        // configurations as possible.
        spl_autoload_register(array($this, 'loadClass'));
        if (function_exists('__autoload')) {
            spl_autoload_register('__autoload');
        }
    }

    /**
     * Search registered mappers in LIFO order.
     */
    public function mapToPath($className)
    {
        if (isset($this->_cache[$className])) {
            return $this->_cache[$className];
        }
        foreach ($this->_mappers as $mapper) {
            if ($path = $mapper->mapToPath($className)) {
                if ($this->_fileExists($path)) {
                    $this->_cache[$className] = $path;
                    return $path;
                }
            }
        }
        $this->_cache[$className] = false;
    }

    protected function _include($path)
    {
        return (bool)include $path;
    }

    protected function _fileExists($path)
    {
        return file_exists($path);
    }
}
