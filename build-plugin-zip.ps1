$ErrorActionPreference = 'Stop'

$RootDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location -LiteralPath $RootDir

$PluginDir = 'site-mode-manager'
$ZipName = 'site-mode-manager.zip'

if (-not (Test-Path -LiteralPath $PluginDir -PathType Container)) {
    throw "No se encontró la carpeta '$PluginDir'."
}

if (Test-Path -LiteralPath $ZipName) {
    Remove-Item -LiteralPath $ZipName -Force
}

Compress-Archive -Path $PluginDir -DestinationPath $ZipName -Force

Write-Host "Creado: $ZipName"
