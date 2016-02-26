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
            $data[] = filesize($path.'/'.$file) . '_' . md5_file($path.'/'.$file);
        }

        return count($data) - count(array_unique($data));
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

            if($file->isDir() && !in_array($file->getFilename(), array('.', '..')))
            {
                $children = $this->getChildrenFiles($path . '/' . $file->getFilename());

                $size = $this->getDirectorySize($path . '/' . $file->getFilename());

                $result[$file->getFilename()] = array(
                    'size' => $this->getFormattedResult($size),
                    'filesCount' => count($children),
                    'duplicatesCount' => $this->getDuplicateCount($path.'/'.$file->getFilename(), $children),
                );
            }
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
    private function getFormattedResult($bytes)
    {
        $unit = array('B', 'KB', 'MB', 'GB', 'TB');

        for ($val = 1, $i = 0;; $val *= 1024, ++$i) {
            if ($bytes < $val * 1024) {
                return ceil($bytes / $val) . ' ' . $unit[$i];
            }
        }

        return 0 . ' ' . $unit[0];
    }
}

$s = new Searcher();
$result = $s->getFiles(Searcher::FORMAT_JSON, __DIR__ . '/data');

var_dump($result);
