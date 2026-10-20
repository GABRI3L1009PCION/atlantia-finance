# Registers a Windows scheduled task "AtlantiaLuzma" that starts Ollama and the Luzma gateway at sign-in and then checks
# every 2 minutes, restarting whatever stopped. Run once, from a normal PowerShell window:
#   powershell -ExecutionPolicy Bypass -File services\atlantia-ai\install-luzma-autostart.ps1
# To remove it: Unregister-ScheduledTask -TaskName AtlantiaLuzma -Confirm:$false
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$script = Join-Path $root 'start-luzma.ps1'

$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$script`""
$logon = New-ScheduledTaskTrigger -AtLogOn
$repeat = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 2)
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 5)

Register-ScheduledTask -TaskName 'AtlantiaLuzma' -Action $action -Trigger @($logon, $repeat) -Settings $settings -Description 'Mantiene Ollama y el gateway de Luzma siempre encendidos.' -Force | Out-Null
Start-ScheduledTask -TaskName 'AtlantiaLuzma'
Write-Host 'Listo: Luzma se iniciará sola al abrir sesión y se reiniciará si se cae.'

# Second task: Laravel's scheduler (every minute). Without it the Luzma watchdog, the fiscal alerts and the other scheduled
# jobs never run on this machine. In production a cron job or a "schedule:work" service does the same.
$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) { $php = (Get-ChildItem 'C:\laragon\bin\php\php-*\php.exe' -ErrorAction SilentlyContinue | Sort-Object FullName -Descending | Select-Object -First 1).FullName }
$app = (Resolve-Path (Join-Path $root '..\..')).Path
if ($php) {
    $schedule = New-ScheduledTaskAction -Execute $php -Argument 'artisan schedule:run' -WorkingDirectory $app
    $every = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
    $quiet = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 5)
    Register-ScheduledTask -TaskName 'AtlantiaScheduler' -Action $schedule -Trigger $every -Settings $quiet -Description 'Ejecuta las tareas programadas de Atlantia Finance cada minuto.' -Force | Out-Null
    Write-Host 'Listo: las tareas programadas de Atlantia (incluido el vigilante de Luzma) corren cada minuto.'
} else {
    Write-Host 'No encontré PHP: registra a mano "php artisan schedule:run" cada minuto.'
}
