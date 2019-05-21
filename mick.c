<h1>{{ $name }}</h1>
{% if ($email) %}
<span>{{ $email }}</span>
{% else %}
<span>{{ $phone }}</span>
{% endif %}
