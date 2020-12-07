## Share a single term-id between multiple taxonomies.

I checked WPs database structure around ``wp_terms`` and ``wp_term_taxonomy`` and thought: "Neat, this is built for sharing terms between taxonomies!"
It actually was [before WP4.2](https://make.wordpress.org/core/2015/06/09/eliminating-shared-taxonomy-terms-in-wordpress-4-3/).
The table ``wp_terms`` [might even be gone one day](https://make.wordpress.org/core/2013/07/28/potential-roadmap-for-taxonomy-meta-and-post-relationships/).

I tried the following (before reading the articles above).

### What you need to know
- Terms are listed in ``wp_terms`` ( multisite: wp_BLOGID_terms).
- Terms are assigned to a taxonomy* in ``wp_term_taxonomy``.
  - 💡 (*taxonomies don't have a db representation, they just exist in PHP.)
  - 💡 taxonomies also define the capability (which is needed to create/edit/delete a term)
  - 💡 the ``wp_term_taxonomies`` table also holds parent-child relations.

### What I tried
- ``wp_insert_term`` does not allow creating terms with the same ids.  
  🔀 Just create it more "manually".
- ``current_user_can('edit_term', $term_id )`` gives you "ambiguous_term_id: Term ID is shared between multiple taxonomies."  
  🔀 There is a workaround with a filter (map_meta_cap): just check if a user has caps from all associated taxonomies.
- Update a term. It is automatically split. This can't be avoided. (search wp-includes/taxonomy.php for "split"). WP even splits with a chron-job.  
  ⚡ Nothing we can do there. So: Lots of redundancy for our use-case...
