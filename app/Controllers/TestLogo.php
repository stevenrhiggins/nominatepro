<?php

namespace App\Controllers;
class TestLogo
{
    public function index(Base $f3)
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $service = new LogoService($f3);
            $path = $service->handleUpload('test-award');
            echo "Uploaded to: $path";
        } else {
            echo '<form method="POST" enctype="multipart/form-data">
                    <input type="file" name="logo">
                    <button>Upload</button>
                  </form>';
        }
    }
}