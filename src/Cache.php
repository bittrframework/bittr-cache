<?php

/**
 * Bittr
 *
 * @license
 *
 * New BSD License
 *
 * Copyright (c) 2017, bittr community
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *      1. Redistributions of source code must retain the above copyright
 *      notice, this list of conditions and the following disclaimer.
 *      2. Redistributions in binary form must reproduce the above copyright
 *      notice, this list of conditions and the following disclaimer in the
 *      documentation and/or other materials provided with the distribution.
 *      3. All advertising materials mentioning features or use of this software
 *      must display the following acknowledgement:
 *      This product includes software developed by the bittrframework.
 *      4. Neither the name of the bittrframework nor the
 *      names of its contributors may be used to endorse or promote products
 *      derived from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY bittrframework ''AS IS'' AND ANY
 * EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL BITTR FRAMEWORK COMMUNITY BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 */

declare(strict_types=1);

namespace Bittr;

use Closure;
use RuntimeException;

class Cache
{
    public const SERIALIZE  = 1;
    public const JSON       = 2;
    public const EXPORT     = 3;

    /** @var array|mixed */
    private static $mapped = ['uid' => 0];
    /** @var string */
    public $save_path = 'Cache/';
    /** @var string n */
    private $map_path = 'map';
    /** @var bool */
    private $exec = true;
    /** @var string|null */
    private $namespace = null;
    /** @var string|null */
    private $name = null;
    /** @var array|mixed */
    private $map = [];
    /** @var null */
    private $group = null;
    /** @var array  */
    private $dirty  = [];

    private $handler = Cache::SERIALIZE;

    /**
     * Cache constructor.
     *
     * @param string|null $name
     * @param string|null $namespace
     * @param string|null $group
     */
    public function __construct(string $name = null, string $namespace = null, string $group = null)
    {
        $path = "{$this->save_path}{$this->map_path}";
        if (is_readable($path))
        {
            self::$mapped = json_decode(file_get_contents($path), true);
        }

        $this->name      = $name;
        $this->map       = self::$mapped;
        $this->namespace = $namespace;
        $this->group     = $group;
    }

    /**
     * Resolves cache based on handler.
     *
     * @param      $data_path
     * @param bool $get
     * @return false|mixed|string
     */
    private function resolve($data_path, bool $get = true)
    {
        if ($get)
        {
            if ($this->handler == self::JSON)
            {
                return json_decode(file_get_contents($data_path), true);
            }
            elseif ($this->handler == self::EXPORT)
            {
                return include($data_path);
            }
            else
            {
                $content = file_get_contents($data_path);
                return function_exists('msgpack_unpack') ? msgpack_unpack($content) : unserialize($content);
            }
        }
        else
        {
            if ($this->handler == self::JSON)
            {
                return json_encode($data_path);
            }
            elseif ($this->handler == self::EXPORT)
            {
                return '<?php return ' . var_export($data_path, true) . ';';
            }
            else
            {
                return function_exists('msgpack_pack') ? msgpack_pack($data_path) : serialize($data_path);
            }
        }
    }

    /**
     * Sets or get cache save path.
     *
     * @param string|null $path
     * @return string
     */
    public function savePath(string $path = null): string
    {
        if ($path)
        {
            $this->save_path = $path;
        }

        return $this->save_path;
    }

    /**
     * Set cache handler.
     *
     * @param int $handler
     * @return \Bittr\Cache
     */
    public function handler(int $handler): Cache
    {
        $this->handler = $handler;

        return $this;
    }

    /**
     * Set executable cache map.
     *
     * @param bool $exec
     * @return \Bittr\Cache
     */
    public function exec(bool $exec): Cache
    {
        $this->exec = $exec;

        return $this;
    }

    /**
     * Sets/Gets cache data.
     *
     * @param \Closure|null $set
     * @param \Closure      $get
     * @return mixed|null
     */
    public function ask(?Closure $set = null, Closure $get = null)
    {
        $this->namespace ? $haystack =& $this->map[$this->namespace] : $haystack =& $this->map;
        $save       = $this->save_path;
        $name       = $this->name;
        $file_exist = isset($haystack[$name]);

        if (is_readable($name))
        {
            $file_time  = filemtime($name);
            if ($file_exist)
            {
                $path = "{$save}{$haystack[$name][1]}";
                if (is_readable($path) && $haystack[$name][0] >= $file_time)
                {
                    $data =  $this->exec ? $this->resolve($path) : file_get_contents($path);
                    return $get ? $get($data) : $data;
                }
            }
        }
        else
        {
            throw new RuntimeException("File \"{$name}\" could not be found. check for access permission.");
        }

        if ($set)
        {
            if (! is_readable($save))
            {
                if (! (new Option($save))->createFolder()->status())
                {
                    throw new RuntimeException("Failed to create directory \"{$save}\". check for access permission.");
                }
            }

            $data = $set($name);
            if ($file_exist)
            {
                // Just update time if already cached.
                $uid = $haystack[$name][1];
                $haystack[$name][0] = time();
            }
            else
            {
                $uid = $this->group ?? (string) ++$this->map['uid'];
                $haystack[$name] = [time(), $uid];
            }

            $path = "{$save}{$uid}";

            if ($this->group)
            {
                if (is_readable($path))
                {
                    if ($this->exec)
                    {
                        $new_data = $this->resolve($path);
                        if (is_array($new_data) && is_array($data))
                        {
                            $data = array_merge($new_data, $data);
                        }
                        else
                        {
                            throw new RuntimeException('Only arrays can be grouped under exec mode.');
                        }
                    }
                    else
                    {
                        $new_data = file_get_contents($path);
                        if (is_scalar($path))
                        {
                            $data .= $new_data;
                        }
                        else
                        {
                            throw new RuntimeException('Only on scalar can be grouped.');
                        }
                    }
                }
            }

            file_put_contents($path, $this->exec ? $this->resolve($data, false) : $data);
            $this->dirty = $this->map;

            return $data;
        }

        return null;
    }

    /**
     * Clears cache data
     *
     * @param bool $silent
     * @return \Bittr\Cache
     * @example (new Cache('file', 'foo'))->clear(); Remove file in foo namespace from cache.
     * @example (new Cache(null, 'foo')  )->clear(); Remove all data in foo namespace from cache.
     * @example (new Cache)              ->clear(); clears all cache
     * @example (new Cache('file')       )->clear(); Remove file from cache
     */
    public function clear(bool $silent = false): Cache
    {
        $name       = $this->name;
        $namespace  = $this->namespace;
        $haystack   =& $this->map;
        if ($namespace)
        {
            if (! isset($this->map[$namespace]) && ! $silent)
            {
                throw new RuntimeException("No cache map found for namespace \"{$namespace}\".");
            }
            else
            {
                $haystack =& $this->map[$namespace];
            }

        }

        if ($name)
        {
            if (isset($haystack[$name]))
            {
                // delete file
                $path = "{$this->save_path}{$haystack[$name][1]}";
                (new Option($path, true, true))->delete();

                unset($haystack[$name]);
                $this->dirty = $this->map;
            }
            elseif (! $silent)
            {
                $map = $this->namespace ? "[{$this->namespace}]{$name}" : $name;
                throw new RuntimeException("No cache map found for \"{$map}\".");
            }


        }
        elseif ($namespace)
        {
            // delete files
            foreach ($haystack as $file)
            {
                $path = "{$this->save_path}{$file[1]}";
                (new Option($path, true, true))->delete();
            }

            unset($this->map[$namespace]);
            $this->dirty = $this->map;
        }
        else
        {
            $this->dirty = [];
            (new Option($this->save_path, true, true))->delete();
        }

        return $this;
    }

    /**
     * Saves cache data.
     */
    public function __destruct()
    {
        if (! empty($this->dirty))
        {
            self::$mapped = $this->dirty;
            file_put_contents("{$this->save_path}{$this->map_path}", json_encode($this->dirty));
        }
    }
}
