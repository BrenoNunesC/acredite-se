# Abre o Acredite.se para teste na rede local (celular e outros PCs da mesma rede).
# Uso, na pasta do projeto:  powershell -ExecutionPolicy Bypass -File .\iniciar-teste.ps1
# Para parar: Ctrl+C nesta janela.
param([int]$Porta = 8080)

$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) {
    $php = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.*\php.exe" -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName
}
if (-not $php) { Write-Host 'PHP não encontrado. Instale com: winget install PHP.PHP.8.4'; exit 1 }

$cfg = Get-Content "$PSScriptRoot\api\config.local.php" -Raw -ErrorAction SilentlyContinue
if ($cfg -match "'debug'\s*=>\s*true") {
    Write-Host "ATENÇÃO: 'debug' => true em api/config.local.php mostra o código de recuperação de senha para quem estiver na rede. Deixe false enquanto o site estiver aberto." -ForegroundColor Yellow
}

$ips = Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | Select-Object -ExpandProperty IPAddress
Write-Host ''
Write-Host 'Acredite.se no ar para a rede local:' -ForegroundColor Green
foreach ($ip in $ips) { Write-Host "  http://${ip}:$Porta" }
Write-Host "  (neste PC: http://localhost:$Porta)"
Write-Host ''
& $php -S "0.0.0.0:$Porta" -t $PSScriptRoot "$PSScriptRoot\servidor-teste.php"
