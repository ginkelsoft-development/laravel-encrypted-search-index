# Voorstel: reactie op PR #48 (guzzlehttp/guzzle ^7.2 → ^8.1)

> Let op: de tool `stel_handeling_voor` was in deze beurt niet beschikbaar
> (alleen Bash/Edit/Glob/Grep/Read/Write). Dit bestand is het voorgestelde
> voorstel voor de PR-reactie, als artefact vastgelegd in de werkplaats zodat
> Zyra/de mens het kan overnemen — er is niets zelf op GitHub geplaatst.

## Advies: **niet mergen** in de huidige vorm

### 1. Guzzle-gebruik in dit package
`src/Services/ElasticsearchService.php` gebruikt uitsluitend
`Illuminate\Support\Facades\Http` / `Illuminate\Http\Client\PendingRequest`.
Er zijn geen directe `GuzzleHttp\*`-klassen in `src/` of `tests/` (ook de
tests mocken via `Http::fake()`, niet via Guzzle's `MockHandler`). Guzzle
wordt alleen indirect aangeroepen via `illuminate/http`. Een major-upgrade
van Guzzle raakt dus geen eigen code van dit package.

### 2. De genoemde CVE-fixes zijn al aanwezig zonder de major-upgrade
Geverifieerd via de officiële GitHub Security Advisories:
- **GHSA-v5mv-p594-2x33** (CVE-2026-69246, noncanonical host bypass):
  "The issue is fixed in `7.15.2` and `8.0.1`" (patches-sectie advisory).
  Vulnerable ranges: `<7.15.2` en `>=8.0.0,<8.0.1`.
- **GHSA-f7vp-7xgx-4w4r** (CVE-2026-69245, noncanonical cookie domain):
  "The issue is fixed in `7.15.2` and `8.0.1`" (patches-sectie advisory).

De huidige `composer.lock` van dit package staat al op
`guzzlehttp/guzzle 7.15.5` (>= 7.15.2) — beide kwetsbaarheden zijn dus al
gedicht, ook zonder deze PR.

### 3. Blocker: breekt Laravel 11/12-ondersteuning
Onderzoek van `illuminate/http`'s eigen `composer.json` (bron van de
`Http`-facade die dit package gebruikt) per Laravel-versie:

| Laravel | guzzlehttp/guzzle eis (illuminate/http) |
|---|---|
| 10.x | geen harde eis (suggest `^7.5`) |
| 11.x | `^7.8.2` |
| 12.x | `^7.8.2` |
| 13.x | `^7.8.2 \|\| ^8.0` |

Dit package claimt in `composer.json` support voor
`illuminate/support: ^10.0 || ^11.0 || ^12.0 || ^13.0`. Een eigen
`composer update --dry-run` met `laravel/framework ^11.0` (resp. `^12.0`)
samen met `guzzlehttp/guzzle ^8.1` geeft een onoplosbaar conflict:

```
laravel/framework[v11.0.0, ..., v11.56.1] require guzzlehttp/guzzle ^7.8.2
  -> found guzzlehttp/guzzle[7.8.2, ..., 7.15.5] but it conflicts with your
     root composer.json require (^8.1).
```
(Identiek voor Laravel 12.x.) Met `laravel/framework ^13.0` installeert
`guzzlehttp/guzzle 8.1.0` wel probleemloos.

Dit komt exact overeen met de CI-uitslag van PR #48 zelf (commit
`b7af147`): `test (8.2, 10.*, ^8.0)`, `test (8.3, 11.*, ^9.0)` en
`test (8.4, 12.*, ^10.0)` falen alle vier runs; alleen
`test (8.4/8.5, 13.*, ^11.0)` slaagt. (Job-logs zelf waren niet
opvraagbaar zonder GitHub-auth (403), maar het faalpatroon per
Laravel-versie is consistent met de dependency-conflict hierboven.)

### Conclusie / aanbeveling
**Niet mergen zoals de PR nu is** — de CI van de PR faalt al op 3 van de
4 ondersteunde Laravel-versies, en dat wordt verklaard door een harde,
onoplosbare composer-dependency-conflict, niet door een toevallige
testfout. Twee opties:

1. **Aanbevolen:** wijzig de eis naar
   `guzzlehttp/guzzle: ^7.8.2 || ^8.0` (net als `illuminate/http` zelf
   doet), i.p.v. een harde `^8.1`. Dat behoudt compatibiliteit met
   Laravel 10–13 en staat gebruikers op Laravel 13 toe om al Guzzle 8 te
   draaien.
2. Als alleen de twee genoemde CVE's het doel zijn: een kleine bump naar
   `^7.15.2` (binnen major 7) volstaat al — de huidige lock heeft de fix
   via 7.15.5 al binnen.

Mocht bewust afscheid genomen worden van Laravel 10/11/12-support, dan
moet dat expliciet in `composer.json`/README en de CI-matrix worden
doorgevoerd, niet stilzwijgend via deze Dependabot-PR.

**Open punt:** CI-job-logs van commit b7af147 waren niet inhoudelijk
raadpleegbaar (403 zonder GitHub-token); de conclusie steunt op de
check-run-namen/conclusies via de publieke API en op de zelf
gereproduceerde `composer update --dry-run`-conflicten.
