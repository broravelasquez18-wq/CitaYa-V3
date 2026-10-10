param([string]$NpmPath = '')
# Inicia el servicio de WhatsApp por QR (tools/whatsapp-qr) si no está corriendo. Lo usa la tarea programada de V3.
$ErrorActionPreference = 'Stop'
$projectPath = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$servicePath = Join-Path $projectPath 'tools\whatsapp-qr'
$mutexName = 'Local\CitaYaWhatsAppQr-' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($projectPath)).Replace('/','_')
$launchMutex = [Threading.Mutex]::new($false, $mutexName)
$ownsMutex = $false
try {
    try { $ownsMutex = $launchMutex.WaitOne(0) } catch [Threading.AbandonedMutexException] { $ownsMutex = $true }
    if (-not $ownsMutex) { return }
    $running = Get-CimInstance Win32_Process -Filter "name = 'node.exe'" | Where-Object { $_.CommandLine -like '*server.mjs*' -and $_.CommandLine -notlike '*Adobe*' } | Select-Object -First 1
    if ($running) { Write-Output 'El servicio de WhatsApp por QR ya está activo.'; return }
    if (-not $NpmPath) { $NpmPath = (Get-Command npm.cmd -ErrorAction Stop).Source }
    $process = Start-Process -FilePath $NpmPath -ArgumentList 'start' -WorkingDirectory $servicePath -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $servicePath 'service.log') -RedirectStandardError (Join-Path $servicePath 'service-error.log')
    Write-Output "Servicio de WhatsApp por QR iniciado. PID: $($process.Id)"
} finally {
    if ($ownsMutex) { $launchMutex.ReleaseMutex() }
    $launchMutex.Dispose()
}
