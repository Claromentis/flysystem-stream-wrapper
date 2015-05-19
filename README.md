# Flysystem stream wrapper

[![Build Status](https://img.shields.io/travis/twistor/flysystem-stream-wrapper/master.svg?style=flat-square)](https://travis-ci.org/twistor/flysystem-stream-wrapper)
[![Coverage Status](https://img.shields.io/scrutinizer/coverage/g/twistor/flysystem-stream-wrapper.svg?style=flat-square)](https://scrutinizer-ci.com/g/twistor/flysystem-stream-wrapper/code-structure)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/twistor/flysystem-stream-wrapper.svg?style=flat-square)](https://packagist.org/packages/twistor/flysystem-stream-wrapper)

##Installation
```
composer require twistor/flysystem-stream-wrapper
```

## Usage
```php
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Twistor\FlysystemStreamWrapper;

// Get a Filesystem object.
$filesystem = new Filesystem(new Local('some/path'));

FlysystemStreamWrapper::register('flysystem', $filesystem);

// Then you can use it like so.
file_put_contents('flysystem://filename.txt', $content);

```
