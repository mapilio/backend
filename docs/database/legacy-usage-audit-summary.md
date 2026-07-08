# Legacy Usage Audit Summary

Date: 2026-07-08

This summary records the current legacy database usage evidence without publishing the full legacy schema inventory.

## Scope

The static audit scanned 916 old application and package files for references to legacy database relations. It looked for evidence in API collection code, runtime controllers, repositories, models, commands, migrations, configuration, and views.

This is static evidence only. A relation with no PHP code reference may still be used by GeoServer, database views, server scripts, scheduled jobs, external tools, or manual operations.

## Findings

| Evidence Signal | Relations |
| --- | ---: |
| API-surface reference | 7 |
| Runtime code reference | 9 |
| Static-only reference | 4 |
| No PHP code reference found | 199 |

Within the owner-decision-needed group:

| Evidence Signal | Relations |
| --- | ---: |
| API-surface reference | 2 |
| Runtime code reference | 5 |
| Static-only reference | 1 |
| No PHP code reference found | 126 |

## Migration Use

Use this audit to sequence owner review:

1. Preserve and contract-test API-surface relations before any schema rewrite.
2. Review runtime-code relations with the product owner to decide migrate, rebuild, or retire.
3. Treat migration-only or static-only references as historical until an active workflow proves otherwise.
4. Check GeoServer layers, scheduled jobs, server-side scripts, materialized views, and external processes before retiring any no-reference relation.
5. Convert confirmed active domains into Laravel migrations and import commands.

## Guardrail

Do not generate migrations for every legacy relation. The new schema should contain only confirmed Mapilio product/runtime data, compatibility mappings, and bounded operational data.
