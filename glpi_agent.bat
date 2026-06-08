@echo off
:: 1. Instalación del agente
:: Sustituye glpi.dominio por la dirección real de tu servidor GLPI
:: Sustituye /qb por /qn si la instalación es silenciosa
:: Sustituye 10.151.180.0/24 por la red que deseas que atienda 
echo Instalando GLPI-Agent...
msiexec /i "GLPI-Agent-1.7.1-x64.msi" /qb /norestart SERVER="http://glpi.eez.csic.es/marketplace/glpiinventory/" RUNNOW=1 ADD_FIREWALL_EXCEPTION=1  ADD_WINDOWS_SERVICE=1 ADDLOCAL=ALL TASKS="Inventory,Deploy" DELAYTIME=60 HTTPD_TRUST="10.151.180.0/24,10.151.120.0/24,127.0.0.1/32"

echo Instalacion finalizada correctamente.
pause