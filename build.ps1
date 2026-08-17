# Packages each plugin into dist/ as a zip Pelican can import.
#
# Pelican resolves a plugin's files by its `id`, so the folder inside the zip must be
# named after that id rather than whatever the working copy happens to be called.
# This reads the id straight out of plugin.json so the two can never drift apart.
#
#   pwsh ./build.ps1

$ErrorActionPreference = 'Stop'

$root = $PSScriptRoot
$dist = Join-Path $root 'dist'
$stage = Join-Path ([System.IO.Path]::GetTempPath()) ('pelican-plugins-' + [guid]::NewGuid().ToString('N'))

New-Item -ItemType Directory -Force -Path $dist | Out-Null
New-Item -ItemType Directory -Force -Path $stage | Out-Null

try {
    $manifests = Get-ChildItem -LiteralPath $root -Directory |
        ForEach-Object { Join-Path $_.FullName 'plugin.json' } |
        Where-Object { Test-Path -LiteralPath $_ }

    if (-not $manifests) {
        throw "No plugins found. Run this from the repository root."
    }

    foreach ($manifest in $manifests) {
        $source = Split-Path -Parent $manifest
        $plugin = Get-Content -LiteralPath $manifest -Raw | ConvertFrom-Json

        if (-not $plugin.id -or -not $plugin.version) {
            throw "$manifest is missing an id or version."
        }

        # A working copy named differently from the id is fine; the zip is what matters.
        $folder = Split-Path -Leaf $source
        if ($folder -ne $plugin.id) {
            Write-Warning "Folder '$folder' does not match id '$($plugin.id)'. Packaging as '$($plugin.id)'."
        }

        $staged = Join-Path $stage $plugin.id
        Copy-Item -LiteralPath $source -Destination $staged -Recurse

        # meta records local install state and has no business in a distributed zip.
        $stagedManifest = Join-Path $staged 'plugin.json'
        $contents = Get-Content -LiteralPath $stagedManifest -Raw | ConvertFrom-Json
        if ($contents.PSObject.Properties.Name -contains 'meta') {
            $contents.PSObject.Properties.Remove('meta')
            $contents | ConvertTo-Json -Depth 20 | Set-Content -LiteralPath $stagedManifest -Encoding utf8
        }

        # Clear older builds of this plugin, otherwise a version change leaves the
        # previous zip sitting in dist/ looking just as current as the new one.
        Get-ChildItem -LiteralPath $dist -Filter "$($plugin.id)-*.zip" -ErrorAction SilentlyContinue |
            Remove-Item -Force

        $zip = Join-Path $dist ("{0}-{1}.zip" -f $plugin.id, $plugin.version)

        Compress-Archive -Path $staged -DestinationPath $zip -CompressionLevel Optimal

        $size = [math]::Round((Get-Item -LiteralPath $zip).Length / 1KB, 1)
        Write-Host ("  {0,-34} {1,7} KB" -f (Split-Path -Leaf $zip), $size)
    }

    Write-Host ""
    Write-Host "Done. Upload from dist/ via Admin -> Plugins -> Import."
}
finally {
    if (Test-Path -LiteralPath $stage) { Remove-Item -LiteralPath $stage -Recurse -Force }
}
