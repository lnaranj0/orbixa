# Instalación en Orbixa (macOS)

1. En la raíz `~/Developer/orbixa` realizar backup de los archivos existentes de Claude (`CLAUDE.md`, `.claude/settings.json`, agentes y skills). Este paquete es una configuración adicional: no sobrescribir versiones anteriores sin comparar.
2. Copiar `CLAUDE.md` y el directorio `.claude/` de este paquete a la raíz. Si OpenSpec inicializó alguna skill de proyecto, preservarla.
3. Verificar JSON: `python3 -m json.tool .claude/settings.json >/dev/null`.
4. Revisar con `git diff -- CLAUDE.md .claude` y confirmar que `.env`, keys y archivos locales no aparecen en el commit.
5. Iniciar `claude` desde la raíz. En Claude ejecutar `/doctor`, `/permissions` y `/agents`, y comprobar las skills disponibles con `/`.
6. Indicar al agente: `Usa architect para revisar 00-platform-foundation; solo plan, sin ediciones ni commits.`
7. Permitir cambios de código solo después del plan y de revisar permisos.

El archivo `settings.json` deja el modo de permisos normal y pide confirmación para comandos sensibles; NO activa permisos irrestrictos. La sintaxis exacta de cualquier regla debe revisarse con `/permissions` en la versión instalada.

## Atribución
`attribution.commit` y `attribution.pr` vacíos quitan texto agregado automáticamente por Claude Code, y `attribution.sessionUrl=false` omite enlaces de sesión. No cambian `git user.name` ni `git user.email`. Los créditos de terceros y políticas organizacionales siguen vigentes.
