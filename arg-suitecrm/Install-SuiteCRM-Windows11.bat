@echo off
setlocal
set "SCRIPT=%~dp0Install-SuiteCRM-Windows11.ps1"
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process powershell.exe -Verb RunAs -ArgumentList '-NoExit','-NoProfile','-ExecutionPolicy','Bypass','-File','%SCRIPT%'"
