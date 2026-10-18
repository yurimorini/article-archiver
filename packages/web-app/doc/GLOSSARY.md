# Article archiver

Eleanor keeps a copy of a page an owner chooses. The copy, the purified body, and the URL that names the page are different things.

## Language

**Archive**:
The stored copy of one page for one owner. That owner has one archive of the page, and the page URL names it.
_Avoid_: Article, bookmark, snapshot

**Article**:
The purified body of a page.
_Avoid_: Archive, page copy

**URL pointer**:
A reference that names a page by its URL. Two pointers that differ only by NUL bytes at either end name the same page. A NUL in the middle is part of the name. A space is part of the name.
_Avoid_: Archive, article, bookmark

**Owner**:
The person an archive belongs to, identified by an email address. Two addresses that differ only in letter case name the same owner.
_Avoid_: user, operator, account
