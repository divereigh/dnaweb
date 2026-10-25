<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class AncestrySwitchController extends Controller
{
    /**
     * Download-and-install page for the Ancestry Switch extension.
     *
     * The extension is served from here rather than handed round as a zip
     * because mail providers bounce zips with JavaScript in them.
     */
    public function index(): Response
    {
        Gate::authorize('switch-ancestry');

        return Inertia::render('AncestrySwitch', [
            'version' => $this->manifest()['version'],
        ]);
    }

    /**
     * The extension/ancestry-switch folder, zipped on the fly so the
     * download is always whatever the last deploy pulled — there's no built
     * copy to forget to refresh. Needs php8.4-zip, which the README's
     * server package list already installs.
     */
    public function download(): BinaryFileResponse
    {
        Gate::authorize('switch-ancestry');

        $dir = base_path('extension/ancestry-switch');
        $tmp = tempnam(sys_get_temp_dir(), 'ancestry-switch');

        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('could not create the extension zip');
        }
        foreach (scandir($dir) as $file) {
            if (is_file("$dir/$file")) {
                // Inside a folder, so unzipping gives the one directory
                // Chrome's "Load unpacked" wants.
                $zip->addFile("$dir/$file", "ancestry-switch/$file");
            }
        }
        $zip->close();

        return response()
            ->download($tmp, 'dnaweb-ancestry-switch-'.$this->manifest()['version'].'.zip', [
                'Content-Type' => 'application/zip',
            ])
            ->deleteFileAfterSend();
    }

    private function manifest(): array
    {
        return json_decode(file_get_contents(base_path('extension/ancestry-switch/manifest.json')), true);
    }
}
