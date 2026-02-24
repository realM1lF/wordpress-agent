# Knowledge

## WordPress Context

This WordPress installation can be customized through the following:

### Site Information
- Site name and description are configurable
- Permalink structure (typically /%postname%/)
- Default post category and format
- Timezone and date format settings

### Content Types
- **Posts:** Blog articles, news, updates
- **Pages:** Static content (About, Contact, etc.)
- **Media:** Images, documents, videos in Media Library
- **Custom Post Types:** May vary by installed plugins

### Common Plugins (if installed)
- SEO plugins (Yoast, RankMath)
- Page builders (Elementor, Divi, Gutenberg patterns)
- Caching plugins (WP Rocket, W3 Total Cache)
- Form plugins (Contact Form 7, Gravity Forms)
- E-commerce (WooCommerce)

## Best Practices

### Content Creation
- Use Gutenberg blocks for structured content
- Add alt text to images
- Use headings hierarchically (H1 → H2 → H3)
- Keep paragraphs short (2-4 sentences)
- Include internal links when relevant

### SEO Basics
- One H1 per page/post
- Meta description under 160 characters
- Use focus keyword in first paragraph
- Optimize images (compress, descriptive filenames)
- Create descriptive permalinks

### Performance
- Use appropriate image sizes
- Don't load unnecessary scripts
- Minimize plugin usage
- Keep WordPress and plugins updated

## Common User Workflows

### Publishing a Blog Post
1. Create draft with title and content
2. Add categories and tags
3. Set featured image
4. Preview and review
5. Schedule or publish

### Updating a Page
1. Edit content
2. Update any broken links
3. Check mobile preview
4. Save changes
5. Clear cache if applicable

### Managing Media
1. Upload to Media Library
2. Add alt text and description
3. Optimize file size
4. Organize in folders (if using media organization plugin)

## Troubleshooting Knowledge

### Common Issues
- **White Screen:** Usually PHP error, check error logs
- **Permalink 404:** Flush permalink settings
- **Slow Admin:** Likely plugin conflict or insufficient hosting
- **Image Upload Fail:** Check file permissions and size limits
- **Update Failed:** Check file permissions (wp-content should be writable)

### Health Checks
- WordPress version up to date?
- PHP version 8.0+?
- SSL certificate valid?
- Database tables optimized?
- Backups working?

## Integration Points

### Available Tools
The agent can interact with WordPress through:
- Post/Page CRUD operations
- Media Library access
- User management (limited)
- Settings (selected, safe ones)
- Taxonomy (categories, tags) management
- Option API (get/update)

### REST API Endpoints
All agent operations use WordPress REST API:
- `/wp/v2/posts` - Posts
- `/wp/v2/pages` - Pages  
- `/wp/v2/media` - Media
- `/wp/v2/users` - Users
- `/wp/v2/settings` - Settings
