# Entry URL and final URL are different facts

The caller string is the entry URL. The canonical URL of the page whose body is extracted is the final URL. Relative links need the final URL, and the caller still needs the string they passed. One field cannot stand for both after a redirect. The hop history tells a redirect apart from canonicalization. Its length is the hop count.
