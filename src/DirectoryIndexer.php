<?php

namespace Mikore\Apt;

class DirectoryIndexer
{
    public static function index($request, $response, $record)
    {
        $files = array_diff(scandir($request->path), ['.', '..']);
        $basepath = Config::get('path', '/var/www/html');

        $dirs = [];
        $filesList = [];

        // Separate directories and files
        foreach ($files as $file) {
            $fullPath = $request->path . '/' . $file;
            if (is_dir($fullPath)) {
                $dirs[] = $file;
            } else {
                $filesList[] = $file;
            }
        }

        // Sort directories and files
        sort($dirs);
        sort($filesList);

        $allFiles = array_merge($dirs, $filesList);
        $relativePath = str_replace($basepath, '', $request->path);
        $title = $relativePath ? "/" . trim($relativePath, '/') : '/';

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow, nosnippet, noarchive"><title>Directory listing for '.$title.'</title><style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:"Arial",sans-serif;background-color:#f8f9fa;color:#333;line-height:1.6;padding:20px}h1{font-size:2em;margin-bottom:20px}table{width:100%;border-collapse:collapse;margin-top:20px}th,td{padding:12px;text-align:left;border-bottom:1px solid #ccc;word-wrap:break-word;max-width:200px}th{background-color:#e9ecef}tr:hover{background-color:#f1f1f1}a{color:#007bff;text-decoration:none}a:visited{color:#7f00ff}a:hover{text-decoration:underline}</style></head><body>';
        $html .= "<h1>Directory listing for $title</h1><table><tr><th>File Name</th><th>Size</th><th>Last Modified</th></tr>";

        if (strlen($request->path) > strlen($basepath) + 1) {
            $filePath = '/' . ltrim(htmlspecialchars(substr(dirname($request->path), strlen($basepath))), '/');
            $html .= "<tr><td><a href=\"$filePath\">..</a></td><td></td><td></td></tr>";
        }

        foreach ($allFiles as $file) {
            $filePath = '/' . ltrim(htmlspecialchars($title . '/' . $file), '/');
            $fullPath = realpath($request->path . '/' . $file);
            $size = is_file($fullPath) ? static::human_filesize(filesize($fullPath)) : '';
            $modified = is_file($fullPath) ? date("d/m/Y H:i:s", filemtime($fullPath)) : '';
            $html .= "<tr><td><a href=\"$filePath\">$file</a></td><td>$size</td><td>$modified</td></tr>";
        }

        $html .= "</table></body></html>";

        $response->header("Content-Type", "text/html");

        if ($request->compress) {
            $response->header("Content-Encoding", "gzip");
            $html = gzencode($html);
        } else {
            $response->header("Content-Length", strlen($html));
        }

        $response->end($html);

        $recored->recordBytes(strlen($html));
    }

    private static function human_filesize($bytes, $dec = 2) {
        $sizes = ['B', 'kB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.{$dec}f %s", $bytes / (1024 ** $factor), $sizes[$factor]);
    }
}
