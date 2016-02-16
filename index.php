<?php
/**
 * Created by PhpStorm.
 * User: user
 * Date: 16.2.16
 * Time: 15.36
 */


class Searcher
{
    const FORMAT_JSON = 'json';
    const FORMAT_ARRAY = 'array';

    /**
     * @param string $path
     * @return int
     */
    protected function getDirectorySize($path)
    {
        $directoryIterator = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);

        $totalSize = 0;
        /** @var DirectoryIterator $file */
        foreach (new RecursiveIteratorIterator($directoryIterator) as $file) {
            $totalSize += $file->getSize();
        }

        return $totalSize;
    }

    /**
     * @param string $path
     * @return DirectoryIterator
     */
    protected function getIterator($path)
    {
        return new DirectoryIterator($path);
    }

    /**
     * @param string $path
     * @return array
     */
    public function getChildrenFiles($path)
    {
        $result = array();
        foreach ($this->getIterator($path) as $file) {
            if ($file->isFile()) {
                $result[] = $file->getFilename();
            }
        }

        return $result;
    }

    /**
     * @param string $path
     * @param array $files
     * @return int
     */
    protected function getDuplicateCount($path, $files)
    {
        $data = array();
        foreach ($files as $file) {
            $data[] = array(
                'size' => filesize($path.'/'.$file),
                'md5' => md5_file($path.'/'.$file),
            );
        }

        $duplicateCount = 0;
        for ($i = 0; $i < count($files) - 1; ++$i) {
            for ($j = $i + 1; $j < count($files); ++$j) {
                if ($data[$i]['size'] === $data[$j]['size'] &&
                    $data[$i]['md5'] === $data[$j]['md5']) {
                    $duplicateCount++;
                }
            }
        }

        return $duplicateCount;
    }

    /**
     * @param string $path
     * @param string $format
     * @return array
     */
    public function getFiles($format = self::FORMAT_ARRAY, $path = __DIR__)
    {
        $result = array();
        /** @var DirectoryIterator $file */
        foreach ($this->getIterator($path) as $file) {

            if (!$file->isDir()) {
                continue;
            }

            if (in_array($file->getFilename(), array('.', '..'))) {
                continue;
            }

            $children = $this->getChildrenFiles($path . '/' . $file->getFilename());

            $size = $this->getDirectorySize($path . '/' . $file->getFilename());

            $result[$file->getFilename()] = array(
                'size' => $this->getFormattedResult($size),
                'filesCount' => count($children),
                'duplicatesCount' => $this->getDuplicateCount($path.'/'.$file->getFilename(), $children),
            );
        }

        uasort($result, function ($left, $right) {
            return $left['size'] < $right['size'];
        });

        return $this->getNormalizeData($result, $format);
    }

    /**
     * @param array $data
     * @param string $format
     * @return array|string
     */
    protected function getNormalizeData($data, $format)
    {
        return $format === self::FORMAT_JSON ? json_encode($data) : $data;
    }

    /**
     * @param int $bytes
     * @return string
     */
    function getFormattedResult($bytes)
    {
        $kb = 1024;
        $mb = $kb * 1024;
        $gb = $mb * 1024;
        $tb = $gb * 1024;

        if (($bytes >= 0) && ($bytes < $kb)) {
            return $bytes . ' B';

        } elseif (($bytes >= $kb) && ($bytes < $mb)) {
            return ceil($bytes / $kb) . ' KB';

        } elseif (($bytes >= $mb) && ($bytes < $gb)) {
            return ceil($bytes / $mb) . ' MB';

        } elseif (($bytes >= $gb) && ($bytes < $tb)) {
            return ceil($bytes / $gb) . ' GB';

        } elseif ($bytes >= $tb) {
            return ceil($bytes / $tb) . ' TB';
        } else {
            return $bytes . ' B';
        }
    }
}

$s = new Searcher();
$result = $s->getFiles(Searcher::FORMAT_JSON, __DIR__ . '/data');

var_dump($result);
