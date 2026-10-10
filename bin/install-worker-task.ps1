$ErrorActionPreference = 'Stop'
# Tarea propia de V3 (nombre distinto al de V2 para no reemplazarla): mantiene activos el procesador
# y el servicio de WhatsApp por QR. Se ejecuta una vez desde PowerShell: .\bin\install-worker-task.ps1
$projectPath = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$workerStarter = Join-Path $PSScriptRoot 'start-worker.ps1'
$qrStarter = Join-Path $PSScriptRoot 'start-whatsapp-qr.ps1'
$phpPath = (Get-Command php -ErrorAction Stop).Source
$npmPath = (Get-Command npm.cmd -ErrorAction Stop).Source
$powershellPath = Join-Path $env:WINDIR 'System32\WindowsPowerShell\v1.0\powershell.exe'
$userName = [Security.Principal.WindowsIdentity]::GetCurrent().Name
$taskName = 'CitaYaV3 - Procesador y WhatsApp QR'
$workerAction = New-ScheduledTaskAction -Execute $powershellPath -Argument ('-NoProfile -NonInteractive -WindowStyle Hidden -File "' + $workerStarter + '" -PhpPath "' + $phpPath + '"') -WorkingDirectory $projectPath
$qrAction = New-ScheduledTaskAction -Execute $powershellPath -Argument ('-NoProfile -NonInteractive -WindowStyle Hidden -File "' + $qrStarter + '" -NpmPath "' + $npmPath + '"') -WorkingDirectory $projectPath
$loginTrigger = New-ScheduledTaskTrigger -AtLogOn -User $userName
$recoveryTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$principal = New-ScheduledTaskPrincipal -UserId $userName -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 2)
Register-ScheduledTask -TaskName $taskName -Action @($workerAction, $qrAction) -Trigger @($loginTrigger,$recoveryTrigger) -Principal $principal -Settings $settings -Description 'Mantiene activos el procesador de CitaYaV3 y el servicio de WhatsApp por QR durante la sesión de Windows. Comprueba cada minuto si necesitan reiniciarse. Aparte de la tarea de CitaYaV2.' -Force | Out-Null
Start-ScheduledTask -TaskName $taskName
Write-Output 'CitaYaV3: arranque al iniciar sesión y recuperación cada minuto configurados (procesador y WhatsApp QR).'
