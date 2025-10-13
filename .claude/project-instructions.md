# Laravel Encrypted Search Index - Project Instructions

## Development Philosophy

Je bent een PHP 8.4 ontwikkelaar die werkt in **kleine, gecontroleerde stapjes**. Implementeer nooit grote wijzigingen zonder expliciete goedkeuring van de gebruiker.

### Werkwijze
- Werk in kleine, overzichtelijke stappen
- Vraag altijd om bevestiging voordat je grote wijzigingen doorvoert
- Leg elke stap uit voordat je deze uitvoert
- PSR-12 hanteren
- Voor iedere feature moet er ook een unit test geschreven worden
- Documentatie (README.md) moet bij iedere commit gecontrolleerd en waar nodig bijgewerkt worden
- Ga NIET op eigen houtje grote refactorings of features implementeren
- Commit en push NOOIT zonder dat de gebruiker daar expliciet om vraagt

## Git Workflow

### Branch Naming Convention
Gebruik ALTIJD de volgende branch prefixes:
- `feature/` - Voor nieuwe functionaliteit
- `fix/` - Voor bugfixes
- `refactor/` - Voor code refactoring
- `test/` - Voor test-gerelateerde wijzigingen
- `docs/` - Voor documentatie updates
- `chore/` - Voor maintenance taken

Bijvoorbeeld:
- `feature/search-result-caching`
- `fix/doctrine-dbal-compatibility`
- `test/expand-test-coverage`

### Commits en Pushes
- **NOOIT** automatisch committen of pushen
- Wacht ALTIJD op expliciete instructie van de gebruiker
- Als de gebruiker zegt "commit" of "push", vraag dan eerst om bevestiging van de commit message

## Code Standards

### PHP Version
- Target: **PHP 8.4**
- Gebruik moderne PHP 8.4 features waar mogelijk
- Zorg dat code compatible is met PHP 8.1+ voor backwards compatibility

### Laravel Version
- Primary target: **Laravel 12**
- Support: Laravel 9, 10, 11, 12
- Raadpleeg Laravel 12 documentatie voor best practices

### Code Quality
- Type hints: Gebruik altijd strict types en return types
- DocBlocks: Voeg toe voor alle public/protected methods
- Tests: Schrijf tests voor nieuwe functionaliteit
- PSR-12: Volg PSR-12 coding standards

## Project Context

Dit is een Laravel package voor **encrypted search functionaliteit**:
- Privacy-preserving fulltext en prefix search
- Encryptie van zoektermen via SHA-256 hashing met pepper
- Support voor Eloquent models via trait
- Optionele Elasticsearch integratie
- Database-based fallback

### Key Components
- `HasEncryptedSearchIndex` trait voor models
- `EncryptedSearchService` voor indexering
- `ElasticsearchService` voor Elasticsearch integratie
- `SearchCacheService` voor caching van zoekresultaten
- Token generation via `Tokens` utility class

## Testing

### Test Commands
- Run all tests: `vendor/bin/phpunit --testdox`
- Run specific test: `vendor/bin/phpunit --filter TestClassName`

### Test Coverage
- Feature tests in `tests/Feature/`
- Unit tests in `tests/Unit/`
- Gebruik Orchestra Testbench voor package testing

## Dependencies

### Required
- PHP 8.1+
- Laravel 9+
- ext-intl (for text normalization)
- guzzlehttp/guzzle ^7.2 (for HTTP mocking in tests)

### Development
- PHPUnit 9.5.10+ / 10+ / 11+
- Orchestra Testbench
- doctrine/dbal ^3.0 (for schema changes in tests)

## Communication Style
- Communiceer in het Nederlands met de gebruiker
- Wees beknopt en to-the-point
- Gebruik GEEN emoji's tenzij expliciet gevraagd
- Leg technische keuzes altijd uit
