# Blog Author Privacy Migration

## Field classification

The legacy routes and their v1 aliases retain the existing `author_detail` object, including `id`, `email`, `user_bio`, `created_at`, and `updated_at`. This bounded change does not claim full PII removal from existing routes. The new v2 routes project each `author_detail` entry to exactly `username` and `user_profile_photo`; a null or missing author remains the existing empty array. `other_authors` already contains only `username` and `user_profile_photo` and is unchanged. All other blog post fields, locale/category filters, ordering, nullable values, and `{data: null}` empty responses are unchanged.

## Endpoint migration

New callers should use `GET /api/v2/content/blogs` and `GET /api/v2/content/blogs/{slug}`. The legacy routes (`/api/get-blogs` and `/api/get-blog-detail/{slug}`) and v1 aliases remain available for unknown third parties and existing clients. V2 list pagination reports the actual 100-row query page size, so 101 matching posts produce two pages; its `path`, `first_page_url`, `last_page_url`, `links`, `next_page_url`, and `prev_page_url` stay on the v2 route and preserve filters. This differs intentionally from the legacy/v1 list metadata, which reports `per_page` 15 despite its 100-row fetch. Detail pagination remains the fixed single-row shape with `per_page` 15. Client rollout is pending and no client code is changed here.

## Callsite evidence

The source projection is in `app/Domain/PublicContent/Queries/BlogContentQuery.php`: `authorDetails` (canonical lines 247-274) reads the full legacy author record, while `mapBlogListRow` and `mapBlogDetailRow` (canonical lines 324-392) place it in the response. Maintained web callsites `BlogPostList` (lines 55 and 270) and `BlogPostDetails` (lines 136 and 327) require `username` and `user_profile_photo`, which are the v2 fields retained for migration. Those callsites are evidence only; the web client is outside this change.

## Monitoring and rollback

Monitor v2 response samples for author entries with keys outside `username,user_profile_photo`, non-v2 pagination URLs, unexpected null/empty behavior, and list totals or page transitions that disagree with the 100-row contract. The focused PHP and OpenAPI contract tests cover these invariants. Roll back by redeploying the prior revision or removing the additive v2 routes/controller; legacy and v1 routes remain the compatibility fallback. No feature flag, wrapper, dependency, or client change is required.
