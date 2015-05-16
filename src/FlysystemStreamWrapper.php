<?php

namespace Twistor;

use League\Flysystem\FileExistsException;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\FilesystemInterface;
use League\Flysystem\RootViolationException;

/**
 * An adapter for Flysystem to a PHP stream wrapper.
 */
class FlysystemStreamWrapper
{
    /**
     * The registered filesystems.
     *
     * @var \League\Flysystem\FilesystemInterface[]
     */
    protected static $filesystems = [];

    /**
     * Default return value of url_stat().
     *
     * @var array
     */
    protected static $defaultMeta = [
        'dev' => 0,
        'ino' => 0,
        'mode' => 0,
        'nlink' => 0,
        'uid' => 0,
        'gid' => 0,
        'rdev' => 0,
        'size' => 0,
        'atime' => 0,
        'mtime' => 0,
        'ctime' => 0,
        'blksize' => -1,
        'blocks' => -1,
    ];

    /**
     * The filesystem of the current stream wrapper.
     *
     * @var \League\Flysystem\FilesystemInterface
     */
    protected $filesystem;

    /**
     * A generic resource handle.
     *
     * @var resource
     */
    protected $handle;

    /**
     * A directory listing.
     *
     * @var array
     */
    protected $listing;

    /**
     * Instance URI (stream).
     *
     * A stream is referenced as "protocol://target".
     *
     * @var string
     */
    protected $uri;

    /**
     * Registers the stream wrapper protocol if not already registered.
     *
     * @param string $protocol The protocol.
     * @param \League\Flysystem\FilesystemInterface $filesystem The filesystem.
     *
     * @return bool True if the protocal was registered, false if not.
     */
    public static function register($protocol, FilesystemInterface $filesystem)
    {
        if (static::streamWrapperExists($protocol)) {
            return false;
        }

        static::$filesystems[$protocol] = $filesystem;
        return stream_wrapper_register($protocol, __CLASS__);
    }

    /**
     * Unegisters a stream wrapper.
     *
     * @param string $protocol The protocol.
     *
     * @return bool True if the protocal was unregistered, false if not.
     */
    public static function unregister($protocol)
    {
        if (!static::streamWrapperExists($protocol)) {
            return false;
        }

        unset(static::$filesystems[$protocol]);
        return stream_wrapper_unregister($protocol);
    }

    /**
     * Determines if a protocol is registered.
     *
     * @param string $protocol The protocol to check.
     *
     * @return bool True if it is registered, false if not.
     */
    protected static function streamWrapperExists($protocol)
    {
        return in_array($protocol, stream_get_wrappers(), true);
    }

    /**
     * {@inheritdoc}
     */
    public function dir_closedir()
    {
        unset($this->listing);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function dir_opendir($uri, $options)
    {
        $this->uri = $uri;
        $this->listing = $this->getFilesystem()->listContents($this->getTarget());

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function dir_readdir()
    {
        $current = current($this->listing);
        next($this->listing);

        return $current ? $current['path'] : false;
    }

    /**
     * {@inheritdoc}
     */
    public function dir_rewinddir()
    {
        reset($this->listing);
    }

    /**
     * {@inheritdoc}
     */
    public function mkdir($uri, $mode, $options)
    {
        $this->uri = $uri;
        // @todo mode and recursive handling.
        return $this->getFilesystem()->createDir($this->getTarget());
    }

    /**
     * {@inheritdoc}
     */
    public function rename($uri_from, $uri_to)
    {
        // Ignore useless renames.
        if ($uri_from === $uri_to) {
            return true;
        }

        $this->uri = $uri_from;

        $path_from = $this->getTarget($uri_from);
        $path_to = $this->getTarget($uri_to);

        return $this->doRename($path_from, $path_to);
    }

    /**
     * Performs a rename.
     *
     * @param string $path_from The source path.
     * @param string $path_to   The destination path.
     *
     * @return bool True if successful, false if not.
     */
    protected function doRename($path_from, $path_to)
    {
        try {
            return $this->getFilesystem()->rename($path_from, $path_to);
        } catch (FileNotFoundException $e) {
            trigger_error(sprintf('%s(%s,%s): No such file or directory', __FUNCTION__, $path_from, $path_to), E_USER_WARNING);
        } catch (FileExistsException $e) {
            // PHP's rename() will overwrite an existing file. Emulate that.
            if ($this->doUnlink($path_to)) {
                return $this->getFilesystem()->rename($path_from, $path_to);
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function rmdir($uri, $options)
    {
        $this->uri = $uri;
        try {
            return $this->getFilesystem()->deleteDir($this->getTarget());
        } catch (RootViolationException $e) {
            trigger_error(sprintf('%s(%s): Cannot remove the root directory', __FUNCTION__, $this->getTarget()), E_USER_WARNING);
        } catch (\UnexpectedValueException $e) {
            // Thrown by a directory interator when the perms fail.
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function stream_cast($cast_as)
    {
        return $this->handle;
    }

    /**
     * {@inheritdoc}
     */
    public function stream_close()
    {
        fclose($this->handle);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_eof()
    {
        return feof($this->handle);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_flush()
    {
        // Calling putStream() will rewind our handle. flush() shouldn't change
        // the position of the file.
        $pos = ftell($this->handle);

        $success = $this->getFilesystem()->putStream($this->getTarget(), $this->handle);

        fseek($this->handle, $pos);

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function stream_lock($operation)
    {
        return flock($this->handle, $operation);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_metadata($uri, $option, $value)
    {
        $this->uri = $uri;

        switch ($option) {
            case STREAM_META_ACCESS:
                // Emulate chmod() since lots of things depend on it.
                // @todo We could do better with the emulation.
                return true;

            case STREAM_META_TOUCH:
                return $this->touch($uri);

            default:
                return false;
        }
    }

    /**
     * Emulates touch().
     *
     * @param string $uri The URI to touch.
     *
     * @return bool True if successful, false if not.
     */
    protected function touch($uri)
    {
        $filesystem = $this->getFilesystem();
        $path = $this->getTarget($uri);

        if (!$filesystem->has($path)) {
            return $filesystem->put($path, '');
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function stream_open($uri, $mode, $options, &$opened_path)
    {
        $this->uri = $uri;
        $path = $this->getTarget();

        $this->handle = $this->getWritableStream($path);

        if ((bool) $this->handle && $options & STREAM_USE_PATH) {
            $opened_path = $path;
        }

        return (bool) $this->handle;
    }

    /**
     * Returns a writable stream given a read-only stream.
     *
     * @param string $path The internal file path.
     *
     * @return resource A writable stream.
     */
    protected function getWritableStream($path)
    {
        $handle = fopen('php://temp', 'r+');

        try {
            $reader = $this->getFilesystem()->readStream($path);
        } catch (FileNotFoundException $e) {
            // We're creating a new file.
            return $handle;
        }

        // Nothing to copy.
        if (!$reader) {
            return $handle;
        }

        // Some adapters are read only streams, so we can't depend on writing to
        // them.
        stream_copy_to_stream($reader, $handle);
        fclose($reader);
        rewind($handle);

        return $handle;
    }

    /**
     * {@inheritdoc}
     */
    public function stream_read($count)
    {
        return fread($this->handle, $count);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_seek($offset, $whence = SEEK_SET)
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    /**
     * {@inheritdoc}
     */
    public function stream_set_option($option, $arg1, $arg2)
    {
        switch ($option) {
            case STREAM_OPTION_BLOCKING:
                return stream_set_blocking($this->handle, $arg1);

            case STREAM_OPTION_READ_TIMEOUT:
                // Not supported yet. There might be a way to use this to pass a
                // timeout to the underlying adapter.
                return false;

            case STREAM_OPTION_WRITE_BUFFER:
                // Not supported. In the future, this could be supported.
                return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stream_stat()
    {
        return fstat($this->handle);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_tell()
    {
        return ftell($this->handle);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_truncate($new_size)
    {
        return ftruncate($this->handle, $new_size);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_write($data)
    {
        return fwrite($this->handle, $data);
    }

    /**
     * {@inheritdoc}
     */
    public function unlink($uri)
    {
        $this->uri = $uri;

        return $this->doUnlink($this->getTarget());
    }

    /**
     * Performs the actual deletion of a file.
     *
     * @param string $path An internal path.
     *
     * @return bool True on success, false on failure.
     */
    protected function doUnlink($path)
    {
        try {
            return $this->getFilesystem()->delete($path);
        } catch (FileNotFoundException $e) {
            trigger_error(sprintf('%s(%s): No such file or directory', 'unlink', $path), E_USER_WARNING);
        } catch (\UnexpectedValueException $e) {
            // Thrown when trying to iterate directories that are unreadable.
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function url_stat($uri, $flags)
    {
        $this->uri = $uri;

        try {
            $metadata = $this->getFilesystem()->getMetadata($this->getTarget());
        } catch (FileNotFoundException $e) {
            return false;
        }

        // It's possible for getMetadata() to fail even if a file exists.
        // @todo Figure out the correct way to handle this.
        if ($metadata === false) {
            return static::$defaultMeta;
        }

        return $this->mergeMeta($metadata);
    }

    /**
     * Merges the available metadata from Filesystem::getMetadata().
     *
     * @param array $metadata The metadata.
     *
     * @return array All metadata with default values filled in.
     */
    protected function mergeMeta(array $metadata)
    {
        $ret = static::$defaultMeta;

        // Dirs are 0777. Files are 0666.
        $ret['mode'] = $metadata['type'] === 'dir' ? 16895 : 33204;

        if (isset($metadata['size'])) {
            $ret['size'] = $metadata['size'];
        }
        if (isset($metadata['timestamp'])) {
            $ret['mtime'] = $metadata['timestamp'];
            $ret['ctime'] = $metadata['timestamp'];
        }

        $ret['atime'] = time();

        return array_merge(array_values($ret), $ret);
    }

    /**
     * Returns the protocol from the internal URI.
     *
     * @return string The protocol.
     */
    protected function getProtocol()
    {
        return substr($this->uri, 0, strpos($this->uri, '://'));
    }

    /**
     * Returns the local writable target of the resource within the stream.
     *
     * @param string $uri (optional) The URI.
     *
     * @return string The path appropriate for use with Flysystem.
     */
    protected function getTarget($uri = null)
    {
        if (!isset($uri)) {
            $uri = $this->uri;
        }

        return substr($uri, strpos($uri, '://') + 3);
    }

    /**
     * Returns the filesystem.
     *
     * @return \League\Flysystem\FilesystemInterface The filesystem object.
     */
    protected function getFilesystem()
    {
        if (isset($this->filesystem)) {
            return $this->filesystem;
        }

        $this->filesystem = static::$filesystems[$this->getProtocol()];
        return $this->filesystem;
    }
}
