
## /folder/?sort=name

List of two folders (example from API documentation):

```json
[  
  {  
    "created": "2013-01-06 13:37:18",  
    "company_id": 147903,  
    "parent": null,  
    "name": "My first folder",  
    "id": 155111,  
    "permissions": {"groups": ["READ"]}  
  },  
  {
    "created": "2013-01-08 12:33:15",  
    "company_id": 147903,  
    "parent": 155111,  
    "name": "My folder",  
    "id": 202531,  
    "permissions": {"groups":["READ","WRITE","ADMIN"]}  
  }  
]
```
Only the Trash folder has anything in it (real example). User doesn't have permissions to view other folders?

```json
[
  {
    "id": 2633575,
    "name": "Trash",
    "parent": null,
    "cover_photo_id": null,
    "company_id": 279698,
    "created": "2026-02-10 10:30:36",
    "permissions": {"groups":["READ","ADMIN","WRITE","TRASH","PERMANENT"]},
    "user_id": 797553,
    "user_fullname": "Anthony Cartmell",
    "user_email": "ajcartmell@fonant.com"
  }
]
````
