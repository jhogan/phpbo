# PHPBO (PHP Business Objects)

## Overview
This custom PHP Object-Relational Mapping (ORM) framework offers a robust and
flexible solution for interacting with database entities using object-oriented
PHP. Inspired by the Active Record design pattern, this framework not only
simplifies the creation, retrieval, updating, and deletion of database records
but also integrates seamlessly with your business logic, ensuring that your
data access layer remains clean and maintainable.

### Key Highlights

- **Ease of Use**: By mirroring database tables to PHP objects, the ORM allows developers to interact with their database by simply manipulating objects rather than dealing with cumbersome SQL queries directly. This leads to cleaner, more readable code and significantly reduces the amount of boilerplate code developers need to write.

- **Rapid Development**: Designed to speed up development processes, the ORM provides tools and functionalities that automate many of the repetitive tasks associated with database handling, such as data validation. This automation supports rapid application development and prototyping, allowing developers to focus more on business logic rather than database management.

- **Scalability and Performance**: With features like lazy loading and caching, the framework is built to handle applications of all sizes, from small websites to large-scale enterprise systems. It optimizes performance and efficiently manages resources, which is crucial for maintaining fast response times as your data grows.

- **Flexibility**: The framework is designed to be highly flexible, allowing it to be easily adapted to a wide range of projects. It supports various database systems and is configurable to fit different architectural styles or development needs.

- **Maintainability**: Following best practices in code organization and separation of concerns, the ORM promotes maintainability. It makes your codebase easier to manage and extend, supporting long-term projects where evolving requirements are a norm.

### Designed For Developers

This ORM is perfect for PHP developers looking for a powerful yet simple tool to handle data persistence in their applications. Whether you are building small personal projects or large-scale business solutions, this ORM framework provides the structure and support needed to efficiently manage and interact with your database. By reducing complexity and focusing on simplicity and performance, it helps developers create enduring applications with less effort and greater impact.

### Community and Contributions

We believe in the power of community and the open-source ethos. This ORM is not just a tool, but a project that thrives on your feedback and contributions. Whether it's by providing feedback, writing documentation, or contributing code, you can help shape its future and ensure it continues to grow and serve the needs of developers around the world.

## Features

### Active Record Implementation
- **Direct Mapping**: Maps each database table to a corresponding PHP class, simplifying database interactions by allowing you to work with objects rather than SQL queries. This approach makes CRUD operations intuitive and consistent across different models.
- **Synchronization**: Ensures that changes made to the model objects are automatically reflected in the database, maintaining synchronization without manual intervention.

### Relationship Management
- **Flexible Associations**: Easily define and manage various types of relationships between models:
  - **One-to-One**: For direct relationship linking one entity to another (e.g., User and Profile).
  - **One-to-Many**: To handle scenarios where a single entity is associated with multiple entities (e.g., Author and Books).
  - **Many-to-Many**: Facilitates the management of complex relationships involving multiple entities on both sides (e.g., Students and Courses through Enrollments).
- **Cascading Operations**: Supports cascading updates and deletes, ensuring data integrity across related entities.

### Validation
- **Data Integrity**: Provides built-in validation rules that are executed before database operations to ensure that only valid data is saved. This reduces errors and exceptions caused by invalid data states.
- **Customizable Validation Rules**: Developers can define custom validation rules per model, allowing for flexible validation logic tailored to specific business requirements.

### Lazy Loading
- **Performance Optimization**: Automatically delays the loading of data until it is actually needed. This lazy loading approach significantly reduces unnecessary data fetching, which can enhance performance, especially in scenarios with complex data models.
- **Resource Management**: Helps in managing system resources more efficiently by avoiding preloading of unnecessary data, which can be crucial for large datasets and in high-traffic environments.

### Additional Features to Consider
- **Soft Deletes**: Instead of permanently removing records from the database, the ORM can mark them as deleted, allowing for recovery and audit trails.
- **Timestamps Management**: Automatically manages creation and update timestamps, ensuring that every record has accurate time tracking without manual updates.
- **Cache Integration**: Provides hooks for caching frequently accessed data, reducing database load and improving response times.

## Installation

### Requirements
- PHP 7.4 or higher
- MySQL 5.7 or higher or any compatible database system
- Composer for managing PHP dependencies

## Usage

### Here is a basic example of how to use the ORM to interact with a Person model:

	// Create a new person
	$person = new Person();
	$person->FirstName('John');
	$person->LastName('Doe');
	$person->Update();

	// Fetch a person from the database
	$person = Person::find(1);
	echo $person->FirstName();

	// Update a person
	$person->LastName('Smith');
	$person->Update();

	// Delete a person
	$person->Delete();

### Checking for Validation Errors

This example shows how to create a new Person, validate the model before
saving, and handle any validation errors:

	// Create a new person
	$person = new Person();
	$person->FirstName('John');
	$person->LastName('Doe');

	// Check for validation errors before saving
	if ($person->IsValid()) {
		$person->Update();  // Persist to database
		echo "Person saved successfully!";
	} else {
		$errors = $person->BrokenRules();
		foreach ($errors->Rules() as $rule) {
			echo "Validation error: " . key($rule) . " - " . current($rule) . "<br/>";
		}
	}

### One-to-Many Relationship Example

Suppose each Person can have multiple Addresses. Here's how you might manage
this relationship:

	// Fetch a person from the database
	$person = Person(1);

	// Adding new addresses to a person
	$address = new Address();
	$address->Street('1234 Elm St');
	$address->City('Metropolis');
	$address->State('NY');
	$address->PostalCode('12345');
	$person->AddAddress($address);

	$address2 = new Address();
	$address2->Street('987 Maple Ave');
	$address2->City('Gotham');
	$address2->State('NY');
	$address2->PostalCode('54321');
	$person->AddAddress($address2);

	// Save the person and their addresses
	if ($person->IsValid()) {
		$person->Update();  // This should also save all addresses associated with the person
		echo "Person and addresses saved successfully!";
	} else {
		$errors = $person->BrokenRules();
		foreach ($errors->Rules() as $rule) {
			echo "Validation error: " . key($rule) . " - " . current($rule) . "<br/>";
		}
	}

	// To retrieve addresses for a person
	$addresses = $person->Addresses();
	foreach ($addresses as $address) {
		echo $address->Street() . ', ' . $address->City() . '<br/>';
	}

### Many-to-Many Relationship Example

Assume a Person can be associated with multiple Movies and vice versa, here is
how you can manage this relationship:

	// Assume $person1 and $movie1 are existing objects fetched from the database
	$person1 = Person(1);
	$movie1 = Movie(1);

	// Associating person with a movie
	$person1->AddMovie($movie1, 'Actor', 'John Doe Character');

	// Saving the new association
	if ($person1->IsValid()) {
		$person1->Update();  // This should also update relationships
		echo "Person-Movie association saved successfully!";
	} else {
		$errors = $person1->BrokenRules();
		foreach ($errors->Rules() as $rule) {
			echo "Validation error: " . key($rule) . " - " . current($rule) . "<br/>";
		}
	}

	// Retrieving all movies for a person
	$movies = $person1->Movies();
	foreach ($movies as $movie) {
		echo $movie->Title() . '<br/>';
	}

	// Retrieving all persons for a movie
	$persons = $movie1->Persons();
	foreach ($persons as $person) {
		echo $person->FirstName() . ' ' . $person->LastName() . '<br/>';
	}

## Contributing

Contributions are what make the open source community such an amazing place to
learn, inspire, and create. Any contributions you make are greatly appreciated.

1. Fork the Project
1. Create your Feature Branch (git checkout -b feature/AmazingFeature)
1. Commit your Changes (git commit -m 'Add some AmazingFeature')
1. Push to the Branch (git push origin feature/AmazingFeature)
1. Open a Pull Request

## License
This project is released into the public domain. Anyone is free to use, modify,
redistribute, or do anything they wish with it. This project is provided "as
is" without any warranties or conditions of any kind, either express or
implied.

For more information on public domain and licensing, refer to [Creative Commons
Public Domain Dedication](https://creativecommons.org/publicdomain/zero/1.0/).

## Contact
Jesse Hogan – jessehogan0@gmail.com

Project Link: https://github.com/jhogan/phpbo
